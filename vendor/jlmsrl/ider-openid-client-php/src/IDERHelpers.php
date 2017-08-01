<?php

namespace IDERConnect;


/**
 * IDER Helpers Class
 */
class IDERHelpers
{

    static function logRotate($text, $fullpath)
    {
        $text = "[" . strftime("%Y-%m-%d %H:%M:%S") . "] " . $text . "\n";

        $fileparts = pathinfo($fullpath);

        $ext = '.' . $fileparts['extension'];
        $filename = $fileparts['filename'];
        $dirname = $fileparts['dirname'];


        if (!is_dir($fileparts['dirname'])) {
            mkdir($dirname, true);
        }


        if (!file_exists($fullpath)) {
            touch($fullpath);
            chmod($fullpath, 0666);
        }

        // 5 mb
        if (@filesize($fullpath) > 5 * 1024 * 1024) {

            // search for available filename
            $n = 1;
            while (file_exists($filename . '.' . $n . $ext)) {
                $n++;
            }

            rename($fullpath, $filename . '.' . $n . $ext);

            touch($fullpath);
            chmod($fullpath, 0666);
        }


        if (!is_writable($fullpath)) {
            error_log("Cannot open log file ($filename$ext)");
        }

        if (!$handle = fopen($fullpath, 'a')) {
            echo "Cannot open file (" . $fullpath . ")";
        }

        if (fwrite($handle, $text) === FALSE) {
            echo "Cannot write to file (" . $fullpath . ")";
        }

        fclose($handle);
    }

    /**
     * A wrapper around base64_decode which decodes Base64URL-encoded data,
     * which is not the same alphabet as base64.
     */
    static function base64url_decode($base64url)
    {
        return base64_decode(static::b64url2b64($base64url));
    }

    /**
     * Per RFC4648, "base64 encoding with URL-safe and filename-safe
     * alphabet".  This just replaces characters 62 and 63.  None of the
     * reference implementations seem to restore the padding if necessary,
     * but we'll do it anyway.
     *
     */
    static function b64url2b64($base64url)
    {
        // "Shouldn't" be necessary, but why not
        $padding = strlen($base64url) % 4;
        if ($padding > 0) {
            $base64url .= str_repeat("=", 4 - $padding);
        }
        return strtr($base64url, '-_', '+/');
    }


}