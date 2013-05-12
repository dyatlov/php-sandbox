<?php

class Ext_Sandbox_PHPValidator
{
    public static function php_syntax_error($code, $tokens = null)
    {
        $braces = 0;
        $inString = 0;

        $isCodeHtml = false;

        if (!$tokens) {
            $tokens = token_get_all('<?php ' . $code);
        }

        // First of all, we need to know if braces are correctly balanced.
        // This is not trivial due to variable interpolation which
        // occurs in heredoc, backticked and double quoted strings
        foreach ($tokens as $token) {
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_CURLY_OPEN:
                    case T_DOLLAR_OPEN_CURLY_BRACES:
                    case T_START_HEREDOC:
                        ++$inString;
                        break;
                    case T_END_HEREDOC:
                        --$inString;
                        break;

                    case T_OPEN_TAG:
                        $isCodeHtml = false;
                        break;
                    case T_CLOSE_TAG:
                        $isCodeHtml = true;
                        break;
                }
            } else if ($inString & 1) {
                switch ($token) {
                    case '`':
                    case '"':
                        --$inString;
                        break;
                }
            } else {
                switch ($token) {
                    case '`':
                    case '"':
                        ++$inString;
                        break;

                    case '{':
                        ++$braces;
                        break;
                    case '}':
                        if ($inString) --$inString;
                        else {
                            --$braces;
                            if ($braces < 0) break 2;
                        }

                        break;
                }
            }
        }

        // Display parse error messages and use output buffering to catch them
        $inString = @ini_set('log_errors', false);
        $token = @ini_set('display_errors', true);
        ob_start();

        // If $braces is not zero, then we are sure that $code is broken.
        // We run it anyway in order to catch the error message and line number.

        // Else, if $braces are correctly balanced, then we can safely put
        // $code in a dead code sandbox to prevent its execution.
        // Note that without this sandbox, a function or class declaration inside
        // $code could throw a "Cannot redeclare" fatal error.

        $braces || $code = "if(0){?><?php {$code}\n" . ($isCodeHtml ? '<?php }' : '}');

        if (false === eval($code)) {
            if ($braces) $braces = PHP_INT_MAX;
            else {
                // Get the maximum number of lines in $code to fix a border case
                false !== strpos($code, "\r") && $code = strtr(str_replace("\r\n", "\n", $code), "\r", "\n");
                $braces = substr_count($code, "\n");
            }

            $code = ob_get_clean();
            $code = strip_tags($code);

            // Get the error message and line number
            if (preg_match("/syntax error, (.+) in .+ on line (\\d+)$/s", $code, $code)) {
                $code[2] = (int)$code[2];
                $code = $code[2] <= $braces
                    ? array($code[1], $code[2])
                    : array('unexpected $end ' . substr($code[1], 14), $braces);
            } else $code = array('syntax error', 0);
        } else {
            ob_end_clean();
            $code = false;
        }

        @ini_set('display_errors', $token);
        @ini_set('log_errors', $inString);

        return $code;
    }

    public static function validatePHPCode($source, $functions = array(), $enable = true)
    {
        $inner_functions = array();

        $func_started = 0;

        $previousToken = null;

        $callStack = array();

        $tokens = token_get_all('<?php ' . $source);

        if ($error = self::php_syntax_error($source, $tokens)) {
            throw new Exception($error[0] . ': ' . $error[1]);
        }

        $previousTokenContent = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($token == '(') {
                    if ($func_started) {
                        $func_started = 0;
                    } else {
                        if ($previousToken[0] == T_STRING) {
                            $funcSearch = implode('::', $callStack);
                            if (strlen($funcSearch) && $funcSearch[0] != '$') {
                                if (!count($callStack) || (!$enable ^ (false === array_search($funcSearch, $functions)))) {
                                    if (!in_array($funcSearch, $inner_functions)) {
                                        throw new Exception('Function is disabled: ' . $funcSearch);
                                    }
                                }
                            }
                        }

                        if (in_array($previousToken[0], array(T_VARIABLE, T_STRING_VARNAME, T_ENCAPSED_AND_WHITESPACE))) {
                            if (!in_array($previousTokenContent, array('+', '-', '*', '/', '.', '^', '&', '?', '!', '%', '@'))) {
                                throw new Exception('Only direct function calls allowed, line ' . $previousToken[2]);
                            }
                        }
                    }
                }

                if ($token != '.') {
                    $callStack = array();
                }
            } else {
                list($id, $text) = $token;

                if (in_array($id, array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE))) {
                    continue;
                }

                switch ($id) {
                    case T_FUNCTION:
                        $func_started = 1;
                        break;
                    case T_STRING:
                        $callStack[] = $text;

                        if ($func_started) {
                            $inner_functions[] = $text;
                        }
                        break;
                    case T_NS_SEPARATOR:
                    case T_DOUBLE_COLON:
                    case T_OBJECT_OPERATOR:
                        break;
                    case T_VARIABLE:
                        $callStack[] = $token[1];
                        break;
                    case T_EVAL:
                        if (!$enable ^ (false === array_search('eval', $functions))) {
                            throw new Exception('Eval is disabled, line ' . $token[2]);
                        }
                        break;
                    case T_PRINT:
                        if (!$enable ^ (false === array_search('print', $functions))) {
                            throw new Exception('Print is disabled, line ' . $token[2]);
                        }
                        break;
                    case T_ECHO:
                        if (!$enable ^ (false === array_search('echo', $functions))) {
                            throw new Exception('Echo is disabled, line ' . $token[2]);
                        }
                        break;
                    case T_EXIT:
                        if (!$enable ^ ((false === array_search('die', $functions)) && (false === array_search('exit', $functions)))) {
                            throw new Exception('Exit/Die is disabled, line ' . $token[2]);
                        }
                        break;
                    case T_THROW:
                        if (!$enable ^ (false === array_search('throw', $functions))) {
                            throw new Exception('Throw is disabled, line ' . $token[2]);
                        }
                        break;
                    default:
                        $callStack = array();
                        break;
                }
            }

            $previousToken = $token;
        }

        return true;
    }
}
