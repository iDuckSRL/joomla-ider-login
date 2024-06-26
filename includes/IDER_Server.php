<?php

/**
 * iDuck SRL
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 ********************************************************************
 * @category     iDuckSRL
 * @package      Joomla.Plugin
 * @subpackage   System.IDer_Login
 * @author       Emanuele Coppola <plugins@ider.com>
 * @copyright    Copyright (c) 2016 - 2024 iDuck SRL
 * @license      https://github.com/iDuckSRL/joomla-ider-login
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

class IDER_Server
{
    /** Server Instance */
    public static $_instance = null;

    /** Options */
    public static $options = null;

    /** Default Settings */
    public static $default_settings = array(
        'ider_client_id' => '',
        'ider_client_secret' => '',
        'ider_scope_name' => '',
        'ider_enable_in_login' => true,
        'ider_redirect_uri' => '',
        'button_css' => '',
        'campaigns_landing_pages' => ''
    );

    function __construct()
    {
        self::init();
    }

    static function init()
    {
        spl_autoload_register(array(__CLASS__, 'autoloader'));

        self::includes();
    }

    /**
     *  IDEROpenIDClient Initializer
     */
    public static function getIDerOpenIdClientIstance()
    {
        $plugin = PluginHelper::getPlugin('system', 'ider_login');
        $pluginParams = new Registry($plugin->params);

        \IDERConnect\IDEROpenIDClient::$IDERLogFile = JPATH_PLUGINS . '/system/ider_login/log/ider-connect.log';

        // Override the base URL with the WP one.
        \IDERConnect\IDEROpenIDClient::$BaseUrl = Uri::getInstance()->base();

        if (is_null(\IDERConnect\IDEROpenIDClient::$_instance)) {
            \IDERConnect\IDEROpenIDClient::$_instance = new \IDERConnect\IDEROpenIDClient(
                $pluginParams->get('ider_client_id', ''),
                $pluginParams->get('ider_client_secret', ''),
                $pluginParams->get('ider_scope_name', '')
            );
        }

        return \IDERConnect\IDEROpenIDClient::$_instance;
    }

    public static function IDerOpenIdClientHandler()
    {
        $input = Factory::getApplication()->input;
        $scope = $input->get('scope', '');

        try {
            $iderconnect = IDER_Server::getIDerOpenIdClientIstance();

            if (!empty($scope)) {
                $iderconnect->setScope($scope);
            }

            $iderconnect->authenticate();

            $userInfo = $iderconnect->requestUserInfo();

            IDER_Callback::handler($userInfo);
        } catch (Exception $e) {
            IDER_Callback::access_denied($e->getMessage());
        } finally {
            exit;
        }
    }

    /**
     * populate the instance if the plugin for extendability
     * @return object plugin instance
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * plugin includes called during load of plugin
     * @return void
     */
    public static function includes()
    {
        //  This should be used only when composer autoload fails to include classes
        //  self::loadPackage(IDER_PLUGIN_DIR.'vendor/phpseclib/phpseclib');
        //  self::loadPackage(IDER_PLUGIN_DIR.'vendor/jlmsrl/ider-openid-client-php');

        require 'IDER_Callback.php';
        require 'IDER_Helpers.php';
        require 'IDER_UserInfoManager.php';
    }

    private static function autoloader($class)
    {
        $path = dirname(__FILE__) . '../';
        $paths = array();
        $exts = array('.php', '.class.php');

        $paths[] = $path;
        $paths[] = $path . 'includes/';

        foreach ($paths as $p)
            foreach ($exts as $ext) {
                if (file_exists($p . $class . $ext)) {
                    require_once($p . $class . $ext);
                    return true;
                }
            }

        return false;
    }

    private static function loadPackage($dir)
    {
        $composer = json_decode(file_get_contents("$dir/composer.json"), 1);
        $namespaces = $composer['autoload']['psr-4'];

        // Foreach namespace specified in the composer, load the given classes
        foreach ($namespaces as $namespace => $classpath) {
            spl_autoload_register(function ($classname) use ($namespace, $classpath, $dir) {
                // Check if the namespace matches the class we are looking for
                if (preg_match("#^" . preg_quote($namespace) . "#", $classname)) {
                    // Remove the namespace from the file path since it's psr4
                    $classname = str_replace($namespace, "", $classname);
                    $filename = preg_replace("#\\\\#", "/", $classname) . ".php";
                    include_once $dir . "/" . $classpath . "/$filename";
                }
            });
        }
    }

}
