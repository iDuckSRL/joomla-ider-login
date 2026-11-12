<?php

/**
 * @package     IDer Login for Joomla
 * @copyright   (C) IDer
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace IDer\Plugin\System\IderLogin\Extension;

\defined('_JEXEC') or die;

use IDer\Plugin\System\IderLogin\Oidc\OidcClient;
use IDer\Plugin\System\IderLogin\Sync\UserMessageException;
use IDer\Plugin\System\IderLogin\Sync\UserSync;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\User\AfterDeleteEvent;
use Joomla\CMS\Event\User\AfterLoginEvent;
use Joomla\CMS\Event\User\LoginButtonsEvent;
use Joomla\CMS\Event\User\LoginEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class IderLogin extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    private const SESSION_KEY = 'plg_system_iderlogin.request';
    private const REQUEST_TTL = 900;
    private const ROUTE_LOGIN = 'iderlogin/login';
    private const ROUTE_CALLBACK = 'iderlogin/callback';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'   => 'onAfterInitialise',
            'onUserLoginButtons'  => 'onUserLoginButtons',
            'onUserAfterDelete'   => 'onUserAfterDelete',
            'onContentPrepareForm' => 'onContentPrepareForm',
        ];
    }

    // ------------------------------------------------------------------
    // Impostazioni
    // ------------------------------------------------------------------

    private function isConfigured(): bool
    {
        return trim((string) $this->params->get('client_id')) !== ''
            && trim((string) $this->params->get('client_secret')) !== ''
            && trim((string) $this->params->get('scope')) !== '';
    }

    private function client(): OidcClient
    {
        return new OidcClient(
            trim((string) $this->params->get('client_id')),
            (string) $this->params->get('client_secret'),
            JPATH_CACHE . '/plg_system_iderlogin'
        );
    }

    /** Indirizzi propri: funzionano anche senza la riscrittura degli URL. */
    public static function routeUrl(string $route): string
    {
        return Uri::root() . 'index.php/' . $route;
    }

    /** Nella pagina di configurazione del plugin mostra l'indirizzo di ritorno esatto. */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->getName() !== 'com_plugins.plugin' || !$this->getApplication()->isClient('administrator')) {
            return;
        }

        $data = $event->getData();
        $element = \is_object($data) ? ($data->element ?? '') : ($data['element'] ?? '');

        if ($element !== 'iderlogin') {
            return;
        }

        $this->loadLanguage();
        $form->setFieldAttribute('callback_note', 'description', Text::sprintf(
            'PLG_SYSTEM_IDERLOGIN_SETUP_DESC',
            htmlspecialchars(self::routeUrl(self::ROUTE_LOGIN), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(self::routeUrl(self::ROUTE_CALLBACK), ENT_QUOTES, 'UTF-8')
        ), 'params');
    }

    // ------------------------------------------------------------------
    // Pulsante "Accedi con IDer"
    // ------------------------------------------------------------------

    public function onUserLoginButtons(LoginButtonsEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app instanceof CMSApplication || !$app->isClient('site') || !$this->isConfigured()) {
            return;
        }

        if (!$app->getIdentity() || !$app->getIdentity()->guest) {
            return;
        }

        try {
            $document = $app->getDocument();
        } catch (\Throwable) {
            return;
        }

        if (!$document || $document->getType() !== 'html') {
            return;
        }

        $this->loadLanguage();
        $wa = $document->getWebAssetManager();
        $wa->registerAndUseStyle('plg_system_iderlogin.login', 'plg_system_iderlogin/iderlogin.css');
        $wa->registerAndUseScript('plg_system_iderlogin.login', 'plg_system_iderlogin/iderlogin.js', [], ['defer' => true]);

        // dopo l'accesso si torna alla pagina corrente (se interna)
        $return = base64_encode(Uri::getInstance()->toString());
        $url    = self::routeUrl(self::ROUTE_LOGIN) . '?return=' . rawurlencode($return);

        $event->addResult([[
            'label'            => 'PLG_SYSTEM_IDERLOGIN_BUTTON',
            'tooltip'          => 'PLG_SYSTEM_IDERLOGIN_BUTTON_DESC',
            'id'               => 'plg_system_iderlogin-' . bin2hex(random_bytes(6)),
            'data-iderlogin-url' => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            // "svg" viene stampato così com'è sia da com_users sia da mod_login
            'svg'              => HTMLHelper::_('image', 'plg_system_iderlogin/ider-logo-white.png', '', ['class' => 'icon', 'width' => 20, 'height' => 20], true),
            'class'            => 'plg_system_iderlogin_button',
            'onclick'          => '',
        ]]);
    }

    // ------------------------------------------------------------------
    // Indirizzi /iderlogin/login e /iderlogin/callback
    // ------------------------------------------------------------------

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        if (!$app instanceof CMSApplication || !$app->isClient('site')) {
            return;
        }

        $path = trim(mb_substr(Uri::getInstance()->getPath(), mb_strlen(Uri::root(true))), '/');
        $path = preg_replace('#^index\.php/#', '', $path);

        if ($path === self::ROUTE_LOGIN) {
            $this->startLogin();
        } elseif ($path === self::ROUTE_CALLBACK) {
            $this->finishLogin();
        }
    }

    private function startLogin(): void
    {
        $app = $this->getApplication();
        $this->loadLanguage();

        $return = $this->safeReturn($app->getInput()->getBase64('return', ''));

        if (!$app->getIdentity()->guest) {
            $app->redirect($return ?: Uri::root());
        }

        if (!$this->isConfigured()) {
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_NOT_CONFIGURED');
        }

        // IDer accetta "openid" + UNO scope di campagna: quello del link (?scope=)
        // sostituisce lo Scope Name delle impostazioni.
        $scope     = trim((string) $this->params->get('scope'));
        $requested = $app->getInput()->getString('scope', '');

        if (preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $requested)) {
            $scope = $requested;
        }

        $request = OidcClient::newRequest();
        $app->getSession()->set(self::SESSION_KEY, [
            'state'  => $request['state'],
            'nonce'  => $request['nonce'],
            'return' => $return,
            'scope'  => $scope,
            't'      => time(),
        ]);

        try {
            $url = $this->client()->authorizationUrl(self::routeUrl(self::ROUTE_CALLBACK), $scope, $request);
        } catch (\Throwable $e) {
            $this->log('cannot start login (' . \get_class($e) . ': ' . $e->getMessage() . ')', Log::ERROR);
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_UNREACHABLE');
        }

        $app->redirect($url);
    }

    private function finishLogin(): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $this->loadLanguage();

        // richiesta salvata all'avvio: si usa una volta sola
        $saved = $app->getSession()->get(self::SESSION_KEY);
        $app->getSession()->remove(self::SESSION_KEY);

        $state = $input->getString('state', '');

        if (!\is_array($saved) || empty($saved['state']) || $state === '' || !hash_equals($saved['state'], $state)) {
            $this->log('callback with missing or wrong state', Log::WARNING);
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_EXPIRED');
        }

        if (($saved['t'] ?? 0) + self::REQUEST_TTL < time()) {
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_EXPIRED');
        }

        $error = $input->getString('error', '');

        if ($error !== '') {
            $this->log('IDer returned error "' . preg_replace('/[^a-z_]/', '', $error) . '"', Log::INFO);
            $this->fail($error === 'access_denied' ? 'PLG_SYSTEM_IDERLOGIN_ERR_CANCELLED' : 'PLG_SYSTEM_IDERLOGIN_ERR_FAILED');
        }

        $code = $input->getString('code', '');

        if ($code === '') {
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_FAILED');
        }

        try {
            $claims = UserSync::normalize($this->client()->finish($code, self::routeUrl(self::ROUTE_CALLBACK), $saved));
            $sync   = new UserSync($this->getDatabase());
            $user   = $sync->syncUser(
                $claims,
                (bool) $this->params->get('allow_registration', 1),
                (bool) $this->params->get('update_data', 1),
                (bool) $this->params->get('allow_backend_users', 0)
            );
            $this->loginUser($user);
            $this->log('user signed in' . ($sync->updated ? ' (updated: ' . implode(', ', array_unique($sync->updated)) . ')' : ''), Log::INFO, (int) $user->id);
        } catch (UserMessageException $e) {
            $this->log('sign-in refused: ' . $e->getMessage(), Log::WARNING);
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->log('sign-in failed (' . \get_class($e) . ': ' . $e->getMessage() . ')', Log::ERROR);
            $this->fail('PLG_SYSTEM_IDERLOGIN_ERR_FAILED');
        }

        $target = $this->landingPageFor((string) ($saved['scope'] ?? ''))
            ?: ($saved['return'] ?? '')
            ?: $this->safeUrl((string) $this->params->get('redirect_url', ''))
            ?: Uri::root();

        $app->redirect($target);
    }

    /** Accesso senza password, come fa il plugin WebAuthn di Joomla. */
    private function loginUser(User $user): void
    {
        $app = $this->getApplication();
        class_exists(Authentication::class, true);

        $response                = new AuthenticationResponse();
        $response->status        = Authentication::STATUS_SUCCESS;
        $response->username      = $user->username;
        $response->fullname      = $user->name;
        $response->email         = $user->email;
        $response->error_message = '';
        $response->language      = $user->getParam('language');
        $response->type          = 'IDer';

        $options    = ['remember' => false, 'action' => 'core.login.site'];
        $dispatcher = $app->getDispatcher();
        PluginHelper::importPlugin('user', null, true, $dispatcher);

        $result  = $dispatcher->dispatch('onUserLogin', new LoginEvent('onUserLogin', ['options' => $options, 'subject' => (array) $response]));
        $results = $result['result'] ?? [];

        if (\in_array(false, (array) $results, true)) {
            throw new UserMessageException('PLG_SYSTEM_IDERLOGIN_ERR_BLOCKED');
        }

        $app->getSession()->set('user', $user);
        $options['user']         = $user;
        $options['responseType'] = $response->type;
        $dispatcher->dispatch('onUserAfterLogin', new AfterLoginEvent('onUserAfterLogin', ['options' => $options, 'subject' => (array) $response]));
    }

    public function onUserAfterDelete(AfterDeleteEvent $event): void
    {
        $user = $event->getUser();

        if (!empty($user['id']) && $event->getDeletingResult()) {
            (new UserSync($this->getDatabase()))->deleteLinksForUser((int) $user['id']);
        }
    }

    // ------------------------------------------------------------------
    // Utilità
    // ------------------------------------------------------------------

    private function fail(string $languageKey): never
    {
        $app = $this->getApplication();
        $app->enqueueMessage(Text::_($languageKey), 'error');
        $app->redirect(Route::_('index.php?option=com_users&view=login', false));
    }

    private function safeReturn(string $base64): string
    {
        $url = $base64 !== '' ? (string) base64_decode($base64, true) : '';

        return $this->safeUrl($url);
    }

    /** Solo indirizzi di questo sito. */
    private function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if ($url[0] === '/' && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
            return $url;
        }

        return Uri::isInternal($url) && preg_match('#^https?://#i', $url) ? $url : '';
    }

    private function landingPageFor(string $scope): string
    {
        if ($scope === '') {
            return '';
        }

        foreach (preg_split('/\R/', (string) $this->params->get('landing_pages', '')) as $line) {
            $parts = explode('=', trim($line), 2);

            if (\count($parts) === 2 && trim($parts[0]) === $scope && ($url = $this->safeUrl(trim($parts[1]))) !== '') {
                return $url;
            }
        }

        return '';
    }

    /** Mai token, segreti o dati personali nel log. */
    private function log(string $message, int $priority, int $userId = 0): void
    {
        static $ready = false;

        if (!$ready) {
            Log::addLogger(['text_file' => 'plg_system_iderlogin.log.php'], Log::ALL, ['iderlogin']);
            $ready = true;
        }

        Log::add($message . ($userId ? ' [user ' . $userId . ']' : ''), $priority, 'iderlogin');
    }
}
