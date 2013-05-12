PHP Sandbox class
===

Just two functions inside:

*  static function php_syntax_error($code, $tokens = null)
*  static function validatePHPCode($source, $functions = array(), $enable = true)

### php_syntax_error

Function checks if php code is valid.

`$code` - php code (without <?php )

`$tokens` - optional parameter, you can pass it on if you already have tokens for the `code` (you can obtain them using `token_get_all` function.

Function returns error in the format: array( Error Mesage, Error Line # )

If there is no error - function will return false.

### validatePHPCode

Function validates php code and returns status of validation (true or false).

`$source` - php code without `<?php` at start

`$functions` - functions to disable/enable.

`$enable` - boolean, if true, then `$functions` will represent list of functions to enable, of false - list of function to disable.

Example:

```
<?php

require 'PHPValidator.php';

$code = <<<PHP
$b = 1;
$c = 2;
$a = $b + $c;
echo $a;

class test {
	public function __construct() {
		echo 'construct';
	}
	public function foo($num) {
		var_dump($num);
	}
}

$test = new test();
$test->foo($a);
PHP;

// validate the code
$validator = new Ext_Sandbox_PHPValidator();
echo 'Status of validation is: ' . var_export(
// we enable only one function - echo, all others will throw error
$validator->validatePHPCode( $code, array('echo'), true)
, true);
```
