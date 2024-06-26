<?php

defined('_JEXEC') or die;

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

class IDER_Helpers
{
    static function logRotate($text, $filename, $ext = 'log')
    {
        $text = "[" .strftime("%Y-%m-%d %H:%M:%S") . "] " . $text . "\n";

        // add basepath
        $filename = IDER_PLUGIN_DIR . 'logs/' . $filename;

        // add the point
        $ext = '.' . $ext;

        if (!file_exists($filename . $ext)) {
            touch($filename . $ext);
            chmod($filename . $ext, 0755);
        }

        // 2 mb
        if (filesize($filename . $ext) > 5 * 1024 * 1024) {

            // search for available filename
            $n = 1;
            while (file_exists($filename . '.' . $n . $ext)) {
                $n++;
            }

            rename($filename . $ext, $filename . '.' . $n . $ext);

            touch($filename . $ext);
            chmod($filename . $ext, 0755);
        }


        if (!is_writable($filename . $ext)) {
            error_log("Cannot open log file ($filename$ext)");
        }

        if (!$handle = fopen($filename . $ext, 'a')) {
            echo "Cannot open file ($filename$ext)";
        }

        if (fwrite($handle, $text) === FALSE) {
            echo "Cannot write to file ($filename$ext)";
        }

        fclose($handle);
    }

    static function isJSON($string){
        return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }
}