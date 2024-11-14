<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInita10ffd184d1449bcfa199abd8fabed35
{
    public static $prefixLengthsPsr4 = array (
        'F' => 
        array (
            'Firebase\\JWT\\' => 13,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Firebase\\JWT\\' => 
        array (
            0 => __DIR__ . '/..' . '/firebase/php-jwt/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInita10ffd184d1449bcfa199abd8fabed35::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInita10ffd184d1449bcfa199abd8fabed35::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInita10ffd184d1449bcfa199abd8fabed35::$classMap;

        }, null, ClassLoader::class);
    }
}