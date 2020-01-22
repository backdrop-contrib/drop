<?php

/*
* @file
*  Assure that context API behaves as designed. Mostly implicitly tested, but we
*  do have some edges that need explicit testing.
*
*  @see brush/includes/context.inc.
*/

class contextCase extends Drop_TestCase {
  function setUpPaths() {
    $this->root = $this->sites[$this->env]['root'];
    $this->site = $this->root . '/sites/' . $this->env;
    $this->home = UNISH_SANDBOX . '/home';
    $this->paths = array(
      'custom' => UNISH_SANDBOX,
      'site' =>  $this->site,
      'backdrop' => $this->root,
      'user' => $this->home,
      'home.brush' => $this->home . '/.brush',
      'system' => UNISH_SANDBOX . '/etc/brush',
      // We don't want to write a file into brush dir since it is not in the sandbox.
      // 'brush' => dirname(realpath(UNISH_DROP)),
    );
    // Run each path through realpath() since the paths we'll compare against
    // will have already run through brush_load_config_file().
    foreach ($this->paths as $key => $path) $this->paths[$key] = realpath($path);
  }

  /**
   * Try to write a tiny brushrc.php to each place that brush checks. Also
   * write a sites/dev/aliases.brushrc.php file to the sandbox.
   */
  function setup() {
    parent::setUp();

    $this->env = 'dev';
    $this->setUpBackdrop($this->env, FALSE);
    $this->setUpPaths();

    // These files are only written to sandbox so get automatically cleaned up.
    foreach ($this->paths as $key => $path) {
      $contents = <<<EOD
<?php
// Written by Brush's contextCase::setup(). This file is safe to delete.

\$options['contextConfig'] = '$key';
\$command_specific['unit-eval']['contextConfig'] = '$key-specific';

EOD;
      $path .= $key == 'user' ? '/.brushrc.php' : '/brushrc.php';
      if (file_put_contents($path, $contents)) {
        $this->written[] = $path;
      }
    }

    // Also write a site alias so we can test its supremacy in context hierarchy.
    $path = $this->site . '/aliases.brushrc.php';
    $aliases['contextAlias'] = array(
      'contextConfig' => 'alias1',
      'command-specific' => array (
        'unit-eval' => array (
          'contextConfig' => 'alias-specific',
        ),
      ),
    );
    $contents = $this->file_aliases($aliases);
    $return = file_put_contents($path, $contents);
  }

  /**
   * These should be different tests but I could not work out how to do that
   * without calling setup() twice. setupBeforeClass() did not work out (for MW).
   */
  function testContext() {
    $this->ConfigSearchPaths();
    $this->ConfigVersionSpecific();
    $this->ContextHierarchy();
  }

  /**
   * Assure that all possible config files get loaded.
   */
  function ConfigSearchPaths() {
    $options = array(
      'pipe' => NULL,
      'config' => UNISH_SANDBOX,
      'root' => $this->root,
      'uri' => $this->env,
    );
    $this->brush('core-status', array('Brush configuration'), $options);
    $output = trim($this->getOutput());
    $loaded = explode(' ', $output);
    $this->assertSame($this->written, $loaded);
  }

  /**
   * Assure that matching version-specific config files are loaded and others are ignored.
   */
  function ConfigVersionSpecific() {
    $major = $this->brush_major_version();
    // Arbitrarily choose the system search path.
    $path = realpath(UNISH_SANDBOX . '/etc/brush');
    $contents = <<<EOD
<?php
// Written by Unish. This file is safe to delete.
\$options['unish_foo'] = 'bar';
EOD;

    // Write matched and unmatched files to the system search path.
    $files = array(
      $path .  '/brush' . $major . 'rc.php',
      $path .  '/drop999' . 'rc.php',
    );
    mkdir($path . '/brush' . $major);
    mkdir($path . '/drop999');
    foreach ($files as $file) {
      file_put_contents($file, $contents);
    }

    $this->brush('core-status', array('Brush configuration'), array('pipe' => NULL));
    $output = trim($this->getOutput());
    $loaded = explode(' ', $output);
    $this->assertTrue(in_array($files[0], $loaded), 'Loaded a version-specific config file.');
    $this->assertFalse(in_array($files[1], $loaded), 'Did not load a mismatched version-specific config file.');
  }

  /**
   * Assure that options are loaded into right context and hierarchy is
   * respected by brush_get_option().
   *
   * Stdin context not exercised here. See backendCase::testTarget().
   */
  function ContextHierarchy() {
    // The 'custom' config file has higher priority than cli and regular config files.
    $eval =  '$contextConfig = brush_get_option("contextConfig", "n/a");';
    $eval .= '$cli1 = brush_get_option("cli1");';
    $eval .= 'print json_encode(get_defined_vars());';
    $config = UNISH_SANDBOX . '/brushrc.php';
    $options = array(
      'cli1' => NULL,
      'config' => $config,
      'root' => $this->root,
      'uri' => $this->env,
    );
    $this->brush('php-eval', array($eval), $options);
    $output = $this->getOutput();
    $actuals = json_decode(trim($output));
    $this->assertEquals('custom', $actuals->contextConfig);
    $this->assertTrue($actuals->cli1);

    // Site alias trumps 'custom'.
    $eval =  '$contextConfig = brush_get_option("contextConfig", "n/a");';
    $eval .= 'print json_encode(get_defined_vars());';
    $options = array(
      'config' => $config,
      'root' => $this->root,
      'uri' => $this->env,
    );
    $this->brush('php-eval', array($eval), $options, '@contextAlias');
    $output = $this->getOutput();
    $actuals = json_decode(trim($output));
    $this->assertEquals('alias1', $actuals->contextConfig);

    // Command specific wins over non-specific. If it did not, $expected would
    // be 'site'. Note we call unit-eval command in order not to purturb
    // php-eval with options in config file.
    $eval =  '$contextConfig = brush_get_option("contextConfig", "n/a");';
    $eval .= 'print json_encode(get_defined_vars());';
    $options = array(
      'root' => $this->root,
      'uri' => $this->env,
      'include' => dirname(__FILE__), // Find unit.brush.inc commandfile.
    );
    $this->brush('unit-eval', array($eval), $options);
    $output = $this->getOutput();
    $actuals = json_decode(trim($output));
    $this->assertEquals('site-specific', $actuals->contextConfig);
  }
}
