<?php
/**
 * Access module
 *
 */

namespace HTRouter\Module\Authz;

class Host Extends \AuthzModule {
    // The different order constants
    const ALLOW_THEN_DENY = 1;
    const DENY_THEN_ALLOW = 2;
    const MUTUAL_FAILURE = 3;

    public function init(\HTRouter $router)
    {
        parent::init($router);

        // Register directive
        $router->registerDirective($this, "allow");
        $router->registerDirective($this, "deny");
        $router->registerDirective($this, "order");

        // Register hook
        $router->registerHook(\HTRouter::HOOK_CHECK_ACCESS, array($this, "checkAccess"));

        // Default value
        $router->getRequest()->setAccessOrder(self::DENY_THEN_ALLOW);
        $router->getRequest()->setAccessDeny(array());
        $router->getRequest()->setAccessAllow(array());
    }


    public function checkUserAccess(\HTRouter\Request $request)
    {
        // Not needed, we are hooking in check_access
    }


    public function allowDirective(\HTRouter\Request $request, $line) {
        if (! preg_match("/^from (.+)$/i", $line, $match)) {
            throw new \UnexpectedValueException("allow must be followed by a 'from'");
        }

        // Convert each item on the line to our custom "entry" object
        foreach ($this->_convertToEntry($match[1]) as $item) {
            $request->appendAccessAllow($item);
        }
    }

    public function denyDirective(\HTRouter\Request $request, $line) {
        if (! preg_match("/^from (.+)$/i", $line, $match)) {
            throw new \UnexpectedValueException("deny must be followed by a 'from'");
        }

        // Convert each item on the line to our custom "entry" object
        foreach ($this->_convertToEntry($match[1]) as $item) {
            $request->appendAccessDeny($item);
        }

    }

    public function orderDirective(\HTRouter\Request $request, $line) {
        // Funny.. Apache does a strcmp on "allow,deny", so you can't have "allow, deny" spaces in between.
        // So we shouldn't allow it either.

        $utils = new \HTRouter\Utils;
        $value = $utils->fetchDirectiveFlags($line, array("allow,deny" => self::ALLOW_THEN_DENY,
                                                          "deny,allow" => self::DENY_THEN_ALLOW,
                                                          "mutual-failure" => self::MUTUAL_FAILURE));
        $request->setAccessOrder($value);
    }


    /**
     * These functions should return true|false or something to make sure we can continue with our stuff?
     *
     * @param \HTRouter\Request $request
     * @return bool
     * @throws \LogicException
     */
    public function checkAccess(\HTRouter\Request $request) {

        // The way we parse things depends on the "order"
        switch ($request->getAccessOrder()) {
            case self::ALLOW_THEN_DENY :
                $result = false;
                if ($this->_findAllowDeny($request->getAccessAllow())) {
                    $result = true;
                }
                if ($this->_findAllowDeny($request->getAccessDeny())) {
                    $result = false;
                }
                break;
            case self::DENY_THEN_ALLOW :
                $result = true;
                if ($this->_findAllowDeny($request->getAccessDeny())) {
                    $result = false;
                }
                if ($this->_findAllowDeny($request->getAccessAllow())) {
                    $result = true;
                }
                break;
            case self::MUTUAL_FAILURE :
                if ($this->_findAllowDeny($request->getAccessAllow()) and
                    !$this->_findAllowDeny($request->getAccessDeny())) {
                    $result = true;
                } else {
                    $result = false;
                }
                break;
            default:
                throw new \LogicException("Unknown order");
                break;
        }

        // Not ok. Now we need to check if "satisfy any" already got a satisfaction
        if ($result == false) {
            if ($request->getSatisfy() == "any") {
                // Check if there is at least one require line in the htaccess. If found, it means that
                // we still have to possibility that we can be authorized

                $requires = $request->getRequire();
                if (is_array($requires) and count($requires) > 0) {
                    // It's ok, we have at least 1 require statement, so we return true nevertheless
                    $request->setAuthorized(true);
                    return \HTRouter::STATUS_DECLINED;
                }
            }

            // Not ok. Satisfy ALL or we didn't find a "require"
            $this->getRouter()->createForbiddenResponse();
            exit;
        }

        // Everything is ok
        $request->setAuthorized(true);
        return \HTRouter::STATUS_DECLINED;
    }

    protected function _findAllowDeny(array $items) {
        $utils = new \HTRouter\Utils;

        // Iterate all "ALLOW" or "DENY" items. We just return if at least one of them matches
        foreach ($items as $entry) {
            switch ($entry->type) {
                case "env" :
                    $env = $this->getRouter()->getRequest()->getEnvironment();
                    if (isset($env[$entry->env])) return true;
                    break;
                case "nenv" :
                    $env = $this->getRouter()->getRequest()->getEnvironment();
                    if (! isset ($env[$entry->env])) return true;
                    break;
                case "all" :
                    return true;
                    break;
                case "ip" :
                    if ($utils->checkMatchingIP($entry->ip, $this->getRouter()->getRequest()->getIp())) return true;
                    break;
                case "host" :
                    if ($utils->checkMatchingHost($entry->host, $this->getRouter()->getRequest()->getIp())) return true;
                    break;
                default:
                    throw new \LogicException("Unknown entry type: ".$entry->type);
                    break;
            }
        }
        return false;
    }

    /**
     * Convert a line to an array of simple entry objects
     *
     * @param $line
     */
    protected function _convertToEntry($line) {
        $entries = array();

        foreach (explode(" ", $line) as $item) {
            $entry = new \StdClass();

            if ($item == "all") {
                $entry->type = "all";
                $entries[] = $entry;
                continue;
            }

            // Must be parsed BEFORE env= is parsed!
            if (substr($item, 0, 5) === "env=!") {
                $entry->type = "nenv";
                $entry->env = substr($item, 5);
                $entries[] = $entry;
                continue;
            }

            if (substr($item, 0, 4) === "env=") {
                $entry->type = "env";
                $entry->env = substr($item, 4);
                $entries[] = $entry;
                continue;
            }

            if (strchr($item, "/")) {
                // IP with subnet mask or cidr
                $entry->type = "ip";
                $entry->ip = $line;
                $entries[] = $entry;
                continue;
            }
            if (preg_match("/^[\d\.]+$/", $line)) {
                // Looks like it's an IP or partial IP
                $entry->type = "ip";
                $entry->ip = $line;
                $entries[] = $entry;
                continue;
            }

            // Nothing found, treat as (partial) hostname
            $entry->type = "host";
            $entry->host = $line;
            $entries[] = $entry;
        }

        return $entries;
    }


    public function getAliases() {
        return array("mod_authz_host.c", "authz_host");
    }

}