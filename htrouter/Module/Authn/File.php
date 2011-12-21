<?php

/**
 * Authenticate against a file (mostly a htpasswd). There can be different ways of storing passwords (md5, sha1, crypt).
 * The HTUtils::validatePassword takes care of that.
 *
 * REALM information is not checked.
 */

namespace HTRouter\Module\Authn;
use HTRouter\ModuleInterface;

class File Extends \AuthnModule {

    public function init(\HTRouter $router)
    {
        parent::init($router);

        $router->registerDirective($this, "AuthUserFile");

        // This is a authorization module, so register it as a provider
        $router->registerProvider(\HTRouter::PROVIDER_AUTHN_GROUP, $this);
    }

    public function authUserFileDirective(\HTRequest $request, $line) {
        if (! is_readable($line)) {
            throw new \RuntimeException("Cannot read authfile: $line");
        }

        $request->setAuthUserFile($line);
    }


    function checkRealm (\HTRequest $request, $user, $realm) {
        // @TODO: unused
    }

    function checkPassword (\HTRequest $request, $user, $pass) {
        $utils = new \HTUtils();

        // Read htpasswd file line by line
        $htpasswdFile = $request->getAuthUserFile();
        foreach (file($htpasswdFile) as $line) {

            // Trim line and parse user/pass
            $line = trim($line);
            if ($line[0] == "#") continue;
            list($chk_user, $chk_pass) = explode(":", $line);

            // Note: case SENSITIVE:  jay != JAY
            if ($chk_user == $user and $utils->validatePassword($pass, $chk_pass)) {
                return \AuthModule::AUTH_GRANTED;
            }
        }

        return \AuthModule::AUTH_DENIED;
    }

    public function getName() {
        return "authn_file";
    }

}