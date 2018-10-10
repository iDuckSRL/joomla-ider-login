<?php

/**
 * Jlm SRL
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://www.ider.com/IDER-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category     Jlmsrl
 * @package      Joomla.Plugin
 * @subpackage   System.IDer_Login
 * @author       Emanuele Coppola <plugins@jlm.srl>
 * @copyright    Copyright (c) 2016 - 2018 Jlm SRL (http://www.jlm.srl)
 * @license      https://www.ider.com/IDER-LICENSE-COMMUNITY.txt
 */

defined('_JEXEC') or die;

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

        $plugin = JPluginHelper::getPlugin('system', 'ider_login');
        $pluginParams = new JRegistry($plugin->params);

        \IDERConnect\IDEROpenIDClient::$IDERLogFile = JPATH_PLUGINS . DS . 'system' . DS . 'ider_login' . DS . 'log' . DS . 'ider-connect.log';

        if (is_null(\IDERConnect\IDEROpenIDClient::$_instance)) {
            \IDERConnect\IDEROpenIDClient::$_instance = new \IDERConnect\IDEROpenIDClient($pluginParams->get('ider_client_id', ''), $pluginParams->get('ider_client_secret', ''), $pluginParams->get('ider_scope_name', ''));
        }

        return \IDERConnect\IDEROpenIDClient::$_instance;
    }


    public static function IDerOpenIdClientHandler()
    {
        $jinput = JFactory::getApplication()->input;
        $scope = $jinput->get('scope', '');
        try {

            $iderconnect = IDER_Server::getIDerOpenIdClientIstance();

            if (!empty($scope)) {
                $iderconnect->setScope($scope);
            }

            $iderconnect->setBaseUrl(JUri::base());

            $iderconnect->authenticate();

            $userInfo = $iderconnect->requestUserInfo();

            // I'll call the IDer handler
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

