<?php
/**
 * NOTICE OF LICENSE.
 *
 * UNIT3D is open-sourced software licensed under the GNU General Public License v3.0
 * The details is bundled with this project in the file LICENSE.txt.
 *
 * @project    UNIT3D
 *
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html/ GNU Affero General Public License v3.0
 * @author     HDVinnie
 */

namespace App\Http\Controllers\Auth;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Group;
use App\Models\Invite;
use App\Rules\Captcha;
use App\Models\UserPrivacy;
use Brian2694\Toastr\Toastr;
use Illuminate\Http\Request;
use App\Models\PrivateMessage;
use App\Models\UserActivation;
use App\Jobs\SendActivationMail;
use App\Models\UserNotification;
use App\Http\Controllers\Controller;
use App\Repositories\ChatRepository;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /**
     * @var ChatRepository
     */
    private $chat;

    /**
     * @var Toastr
     */
    private $toastr;

    /**
     * RegisterController Constructor.
     *
     * @param ChatRepository $chat
     * @param Toastr         $toastr
     */
    public function __construct(ChatRepository $chat, Toastr $toastr)
    {
        $this->chat = $chat;
        $this->toastr = $toastr;
    }

    /**
     * Registration Form.
     *
     * @param $code
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function registrationForm($code = null)
    {
        // Make sure open reg is off and invite code is present
        if ($code === 'null' && config('other.invite-only') == 1) {
            return view('auth.login')
                ->with($this->toastr->error('Você tem que ser convidado para se registrar! Você vai ser redirecionado para página de login!', 'Registro não está aberto!', ['options']));
        }

        return view('auth.register', ['code' => $code]);
    }

    public function register(Request $request, $code = null)
    {
        // Make sure open reg is off and invite code exist and has not been used already
        $key = Invite::where('code', '=', $code)->first();
        if (config('other.invite-only') == 1 && (! $key || $key->accepted_by !== null)) {
            return view('auth.register', ['code' => $code])
                ->with($this->toastr->error('Código de convite inválido ou expirado!', 'Whoops!', ['options']));
        }

        $validatingGroup = Group::where('slug', '=', 'validating')->select('id')->first();
        $user = new User();
        $user->username = $request->input('username');
        $user->email = $request->input('email');
        $user->password = Hash::make($request->input('password'));
        $user->passkey = md5(uniqid().time().microtime());
        $user->rsskey = md5(uniqid().time().microtime().$user->password);
        if (config('other.invite-only') == 0 && $code == 'ggames') {
            $user->uploaded = 161061273600;
            $user->invites = 5;

        } else {
            $user->uploaded = config('other.default_upload');
        }

        $user->downloaded = config('other.default_download');
        $user->style = config('other.default_style', 0);
        $user->locale = config('app.locale');
        $user->group_id = $validatingGroup->id;

        if (config('email-white-blacklist.enabled') === 'allow' && config('captcha.enabled') == true) {
            $v = validator($request->all(), [
                'username'             => 'required|alpha_dash|min:3|max:20|unique:users',
                'email'                => 'required|email|max:255|unique:users|email_list:allow', // Whitelist
                'password'             => 'required|min:8',
                'g-recaptcha-response' => new Captcha(),
            ]);
        } elseif (config('email-white-blacklist.enabled') === 'allow') {
            $v = validator($request->all(), [
                'username' => 'required|alpha_dash|min:3|max:20|unique:users',
                'email'    => 'required|email|max:255|unique:users|email_list:allow', // Whitelist
                'password' => 'required|min:8',
            ]);
        } elseif (config('email-white-blacklist.enabled') === 'block' && config('captcha.enabled') == true) {
            $v = validator($request->all(), [
                'username'             => 'required|alpha_dash|min:3|max:20|unique:users',
                'email'                => 'required|email|max:255|unique:users|email_list:block', // Blacklist
                'password'             => 'required|min:8',
                'g-recaptcha-response' => new Captcha(),
            ]);
        } elseif (config('email-white-blacklist.enabled') === 'block') {
            $v = validator($request->all(), [
                'username' => 'required|alpha_dash|min:3|max:20|unique:users',
                'email'    => 'required|email|max:255|unique:users|email_list:block', // Blacklist
                'password' => 'required|min:8',
            ]);
        } elseif (config('captcha.enabled') == true) {
            $v = validator($request->all(), [
                'username'             => 'required|alpha_dash|min:3|max:20|unique:users',
                'email'                => 'required|email|max:255|unique:users',
                'password'             => 'required|min:8',
                'g-recaptcha-response' => new Captcha(),
            ]);
        } else {
            $v = validator($request->all(), [
                'username' => 'required|alpha_dash|min:3|max:20|unique:users', //Default
                'email'    => 'required|email|max:255|unique:users',
                'password' => 'required|min:8',
            ]);
        }

        if ($v->fails()) {
            return redirect()->route('register', ['code' => $code])
                ->with($this->toastr->error($v->errors()->toJson(), 'Whoops!', ['options']));
        } else {
            $user->save();

            $privacy = new UserPrivacy();
            $privacy->setDefaultValues();
            $privacy->user_id = $user->id;
            $privacy->save();

            $notification = new UserNotification();
            $notification->setDefaultValues();
            $notification->user_id = $user->id;
            $notification->save();

            if ($key) {
                // Update The Invite Record
                $key->accepted_by = $user->id;
                $key->accepted_at = new Carbon();
                $key->save();
            }

            // Handle The Activation System
            $token = hash_hmac('sha256', $user->username.$user->email.str_random(16), config('app.key'));
            $activation = new UserActivation();
            $activation->user_id = $user->id;
            $activation->token = $token;
            $activation->save();
            $this->dispatch(new SendActivationMail($user, $token));

            // Select A Random Welcome Message
            $profile_url = hrefProfile($user);

            $welcomeArray = [
                "[url={$profile_url}]{$user->username}[/url], Seja bem vindo ao ".config('other.title').'! Espero que você curta a comunidade! :rocket:',
                "[url={$profile_url}]{$user->username}[/url], Nós estávamos esperando você! :space_invader:",
                "[url={$profile_url}]{$user->username}[/url] chegou. A festa acabou. :cry:",
                "É um pássaro! É uma avião! Ah, deixa, é só o [url={$profile_url}]{$user->username}[/url].",
                "Uploader pronto [url={$profile_url}]{$user->username}[/url].",
                "O corajoso [url={$profile_url}]{$user->username}[/url] apareceu.",
                'Seja bem bindo '.config('other.title')." [url={$profile_url}]{$user->username}[/url]. Estávamos te esperando ( ͡° ͜ʖ ͡°)",
            ];
            $selected = mt_rand(0, count($welcomeArray) - 1);

            $this->chat->systemMessage(
                "{$welcomeArray[$selected]}"
            );

            // Send Welcome PM
            $pm = new PrivateMessage();
            $pm->sender_id = 1;
            $pm->receiver_id = $user->id;
            $pm->subject = config('welcomepm.subject');
            $pm->message = config('welcomepm.message');
            $pm->save();

            // Activity Log
            \LogActivity::addToLog('Member '.$user->username.' has successfully registered to site.');

            return redirect()->route('login')
                ->with($this->toastr->success('Verifique seu e-mail para concluir seu cadastro. (Abra SPAM e a lixeira!!!)', 'Obrigado por se registrar!', ['options']));
        }
    }
}
