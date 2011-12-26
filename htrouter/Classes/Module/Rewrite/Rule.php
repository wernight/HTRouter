<?php

namespace HTRouter\Module\Rewrite;

use HTRouter\Module\Rewrite\Condition;
use HTRouter\Module\Rewrite\Flag;


class Rule {
    const TYPE_PATTERN_UNKNOWN = 0;

    const TYPE_SUB_UNKNOWN   = 0;
    const TYPE_SUB           = 1;
//    const TYPE_SUB_FILE_PATH = 1;
//    const TYPE_SUB_URL_PATH  = 2;
//    const TYPE_SUB_ABS_URL   = 3;
    const TYPE_SUB_NONE      = 4;

    protected $_match = null;                // True is rule matches, false otherwise.

    protected $_conditions = array();        // All rewrite conditions in order

    protected $_request;

    function __construct(\HTRouter\Request $request, $pattern, $substitution, $flags) {
        $this->_request = $request;

        // Set default values
        $this->_pattern = $pattern;
        $this->_patternNegate = false;

        $this->_substitution = $substitution;
        $this->_substitutionType = self::TYPE_SUB_UNKNOWN;

        $this->_flags = array();

        $this->_parsePattern($pattern);
        $this->_parseSubstitution($substitution);
        $this->_parseFlags($flags);
    }

    function getRequest() {
        return $this->_request;
    }

    /**
     * Add a new condition to the list
     *
     * @param Condition $condition
     */
    public function addCondition(Condition $condition) {
        // We need this, since it's possible we need to do a back-reference to the rule from inside a condition
        $condition->linkRule($this);

        // Add condition
        $this->_conditions[] = $condition;
    }

    public function getCondititions() {
        return $this->_conditions;
    }

    /**
     * Returns true if the rule matches, false otherwise. We don't mind non-deterministic conditions like TIME_*
     *
     * @return bool
     */
    public function matches() {
        if ($this->_match == null) {
            // Cache it
            $this->_match = $this->_checkMatch();
        }

        return $this->_match;
    }

    protected function _parsePattern($pattern) {
        if ($pattern[0] == "!") {
            $this->_patternNegate = true;
            $this->_pattern = substr($pattern, 1);
        } else {
            $this->_pattern = $pattern;
        }
    }

    protected function _parseSubstitution($substitution) {
        if ($substitution == "-") {
            $this->_substitutionType = self::TYPE_SUB_NONE;
            $this->_substitution = $substitution;
        } else {
            $this->_substitutionType = self::TYPE_SUB;
            $this->_substitution = $substitution;
        }
    }

    protected function _parseFlags($flags) {
        if (empty($flags)) return;

        // Check for brackets
        if ($flags[0] != '[' && $flags[strlen($flags)-1] != ']') {
            throw new \UnexpectedValueException("Flags must be bracketed");
        }

        // Remove brackets
        $flags = substr($flags, 1, -1);

        foreach (explode(",",$flags) as $flag) {
            $flag = trim($flag);
            $key = null;
            $value = null;

            // Remove value if found (ie: cookie=TEST:VALUE)
            if (strpos("=", $flag)) {
                list($flag, $value) = explode("=", $flag, 2);

                if (strpos(":", $value)) {
                    list($key, $value) = explode(":", $value, 2);
                }
            }

            switch (strtolower($flag)) {
                case "b" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "chain" :
                case "c" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "cookie" :
                case "co" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "discardpath" :
                case "dpi" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "env" :
                case "e" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "forbidden" :
                case "f" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "gone" :
                case "g" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "handler" :
                case "h" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "last" :
                case "l" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "next" :
                case "n" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "nocase" :
                case "nc" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "noescape" :
                case "ne" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "nosubreqs" :
                case "ns" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "proxy" :
                case "p" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "passthrough" :
                case "pt" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "qsappend" :
                case "qsa" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "redirect" :
                case "r" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "skip" :
                case "s" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                case "type" :
                case "t" :
                    $this->_flags[] = new Flag(Flag::TYPE_BEFORE, $key, $value);
                    break;
                default :
                    throw new \UnexpectedValueException("Unknown flag found in rewriterule");
                    break;
            }

        }
    }

    function hasFlag($type) {
        foreach ($this->_flags as $flag) {
            if ($flag->getType() == $type) {
                return true;
            }
        }
        return false;
    }

    protected function _checkMatch() {
        // Returns true if the rule match, false otherwise

        // First, check conditions
        foreach ($this->getCondititions() as $condition) {
            // Check if condition matches
            $match = $condition->matches();

            print "CONDITION ".$condition." match: ".($match?"true":"false")."<br>\n";

            // Check if we need to AND or OR
            if (! $match && ! $condition->hasFlag(Flag::TYPE_ORNEXT)) {
                // Condition needs to be AND'ed, so it cannot match
                print "AND: Skipping rest of conditions!<br>";
                return false;
            }

            if ($match && $condition->hasFlag(Flag::TYPE_ORNEXT)) {
                // condition needs to be OR'ed and we have already a match, no need to continue
                print "OR: Skipping rest of conditions!<br>";
                return true;
            }
        }

        return true;
    }
}
