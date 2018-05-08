<?php

namespace Drupal\Tests\htmlpurifier\Kernel;

use Drupal\filter\FilterPluginCollection;
use Drupal\KernelTests\KernelTestBase;

class HtmlPurifierFilterTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['system', 'filter', 'htmlpurifier'];

  /**
   * @var \Drupal\filter\Plugin\FilterInterface
   */
  protected $filter;

  protected function setUp() {
    parent::setUp();

    $manager = $this->container->get('plugin.manager.filter');
    $bag = new FilterPluginCollection($manager, []);
    $this->filter = $bag->get('htmlpurifier');
  }

  public function testMaliciousCode() {
    $input = '<img src="javascript:evil();" onload="evil();" />';
    $expected = '';
    $processed = $this->filter->process($input, 'und')->getProcessedText();
    self::assertSame($expected, $processed);
  }

  public function testRemoveEmpty() {
    $input = '<a></a>';
    $expected = '<a></a>';
    $processed = $this->filter->process($input, 'und')->getProcessedText();
    self::assertSame($expected, $processed);

    $config = \Drupal::service('config.factory')->getEditable('htmlpurifier.config_directives');
    $config->set('AutoFormat.RemoveEmpty', TRUE)->save();
    $expected = '';
    $processed = $this->filter->process($input, 'und')->getProcessedText();
    self::assertSame($expected, $processed);
  }

}
