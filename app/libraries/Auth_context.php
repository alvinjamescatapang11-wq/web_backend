<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth_context
{
    private static $user;

    public static function set_user(array $user)
    {
        self::$user = $user;
    }

    public static function user()
    {
        return self::$user;
    }

    public static function clear()
    {
        self::$user = null;
    }
}
