<?php

/**
 * @file
 * Contains \Drupal\htmlpurifier\Plugin\Filter\HTMLPurifierFilter.
 */

namespace Drupal\htmlpurifier\Plugin\Filter;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Annotation\Translation;
use Drupal\filter\Annotation\Filter;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Language\Language;
use Drupal\Core\Form\FormStateInterface;

use \HTMLPurifier_Printer_ConfigForm;

/**
 * A filter that removes malicious HTML and ensures standards compliant output.
 *
 * @Filter(
 *   id = "htmlpurifier",
 *   title = @Translation("HTMLPurifier"),
 *   description = @Translation("Removes malicious HTML code and ensures that the output is standards compliant."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_HTML_RESTRICTOR,
 *   settings = {
 *     "htmlpurifier_config_name" = "htmlpurifier_basic"
 *   },
 *   weight = 20
 * )
 */

class HTMLPurifierFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    // Dry run, testing for errors:
    $text = 'dry run text';
    $cache_id = Crypt::hmacBase64($text, 'htmlpurifier');
    $this->process($text, Language::LANGCODE_NOT_SPECIFIED, TRUE, $cache_id);

    $module_path = drupal_get_path('module', 'htmlpurifier');
    $infos = htmlpurifier_get_info();

    $form['#attached']['css'][] = "$module_path/config-form.css";
    $form['#attached']['js'][] = "$module_path/config-form.js";
    $form['#attached']['js'][] = array(
      'data' => HTMLPurifier_Printer_ConfigForm::getJavaScript(),
      'type' => 'inline',
    );

    // An AJAX request calls the form builder function for every change.
    // Here we grab the config_name so we can grab applicable data from the
    // htmlpurifier_info
    if (isset($form_state['values']['filters']['htmlpurifier']['settings']['htmlpurifier_config_name'])) {
      $config_name = $form_state['values']['filters']['htmlpurifier']['settings']['htmlpurifier_config_name'];
    }
    elseif (isset($this->settings['htmlpurifier_config_name'])) {
      $config_name = $this->settings['htmlpurifier_config_name'];
    }
    else {
      $config_name = $defaults['htmlpurifier_config_name'];
    }

    // Check if we need to reset the form
    $reset = isset($form_state['htmlpurifier']['htmlpurifier_config_reset']) ? $form_state['htmlpurifier']['htmlpurifier_config_reset'] : FALSE;

    $form['htmlpurifier_help'] = array(
      '#type' => 'checkbox',
      '#title' => t('Display Help Text'),
      '#default_value' => isset($this->settings['htmlpurifier_help']) && !$reset ? $this->settings['htmlpurifier_help'] : $infos[$config_name]['htmlpurifier_help'],
      '#description' => t('If enabled, a short note will be added to the filter tips explaining that HTML will be transformed to conform with HTML standards. You may want to disable this option when the HTML Purifier is used to check the output of another filter like BBCode.'),
    );

    $form['htmlpurifier_filter_help'] = array(
      '#type' => 'textfield',
      '#title' => t('Help Text'),
      '#default_value' => isset($this->settings['htmlpurifier_filter_tips']) && !$reset ? $this->settings['htmlpurifier_filter_tips'] : $infos[$config_name]['htmlpurifier_filter_tips'],
      '#description' => t('Override the default help text to be something more useful.'),
      '#states' => array(
        // Hide the settings when the htmlpurifier_help checkbox is disabled.
        'invisible' => array(
          ':input[name="filters[htmlpurifier][settings][htmlpurifier_help]"]' => array(
            'checked' => FALSE,
          ),
        ),
      ),
    );

    // Sort the radio values by weight
    uasort($infos, '_htmlpurifier_option_cmp');
    $options = array();
    foreach ($infos as $name => $info) {
      $options += array(
        $name => t($info['name']) . ': ' . t($info['description'])
      );
    }

    // Radio buttons for the config you want to use
    $form['htmlpurifier_config_name'] = array(
      '#title' => t("Choose an HTML Purifier Configuration"),
      '#type' => 'radios',
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $config_name,
      '#ajax' => array(
        'callback' => '_htmlpurifer_config_form_ajax_callback',
        'wrapper' => 'htmlpurifier_config_form',
      ),
    );

    $intro = '<div class="form-item"><div class="description">'
      . t('Please click on a directive name for more information on what it does before enabling or changing anything!
        Changes will not apply to old entries until you clear the cache (see the <a href="@url">settings page</a>).',
        array('@url' => url('admin/config/content/htmlpurifier'))) . '</div></div>';

    $allowed = $infos[$config_name]['allowed'];

    // Generate the custom HTML Purifier Config form
    $config = _htmlpurifier_get_config($this, $config_name, $reset);
    $config_form = new HTMLPurifier_Printer_ConfigForm($this->settings['htmlpurifier_config_name'] . '_config', 'http://htmlpurifier.org/live/configdoc/plain.html#%s');

    // This entire form element will be replaced whenever
    // 'htmlpurifier_config_name' is updated.
    $form['htmlpurifier_config_form'] = array(
      '#markup' => $intro . $config_form->render($config, $allowed, FALSE),
      '#prefix' => '<div id="htmlpurifier_config_form">',
      '#suffix' => '</div>',
      '#after_build' => array('_htmlpurifier_set_config'),
    );

    // Adds a new button to clear the form.
    // There is no difference between the validate and submit handler
    // so they are both pointed to the same function.
    $form['htmlpurifier_config_reset'] = array(
      '#type' => 'submit',
      '#value' => 'Reset to Defaults',
      '#validate' => array('_htmlpurifier_config_reset'),
      '#submit' => array('_htmlpurifier_config_reset'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    _htmlpurifier_process_text($text, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function getHTMLRestrictions() {
    $restrictions = array();

    $config = _htmlpurifier_get_config($this);
    $definitions = $config->getHTMLDefinition();
    $allowed_elements = $definitions->info_parent_def->child->getAllowedElements($config);
    foreach ($allowed_elements as $tag => $allowed) {
      $restrictions[$tag] = $allowed;
    }
    foreach ($definitions->info as $tag => $definition) {
      if (!empty($definition->attr) && is_array($definition->attr)) {
        $restrictions[$tag] = array();
        foreach (array_keys($definition->attr) as $attribute) {
          $restrictions[$tag][$attribute] = TRUE;
        }
      }
    }

    return $restrictions;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return t('HTML tags will be transformed to conform to HTML standards.');
  }

}
