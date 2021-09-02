<?php

namespace Drupal\social_metatag_preview\Plugin\Field\FieldWidget;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\metatag\Plugin\Field\FieldWidget\MetatagFirehose;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Advanced widget for metatag field with social preview.
 *
 * @FieldWidget(
 *   id = "social_metatag_preview",
 *   label = @Translation("Advanced meta tags form with social preview"),
 *   field_types = {
 *     "metatag"
 *   }
 * )
 */
class SocialMetatagPreview extends MetatagFirehose {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $field_name = $items->getName();
    $entity = $items->getEntity();
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface) {
      // On entity forms, use the current entity directly from the edit form.
      // This picks up all metatag and content edits from the form.
      $entity = $form_object->buildEntity($form, $form_state);
    }
    $default_tags = metatag_get_default_tags($entity);

    // Retrieve the values for each metatag from the serialized array.
    $values = [];
    if (!empty($entity->$field_name->value)) {
      $values = unserialize($entity->$field_name->value);
    }

    // Populate fields which have not been overridden in the entity.
    if (!empty($default_tags)) {
      foreach ($default_tags as $tag_id => $tag_value) {
        if (!isset($values[$tag_id]) && !empty($tag_value)) {
          $values[$tag_id] = $tag_value;
        }
      }
    }

    $tags = metatag_get_tags_from_route($entity);
    $tag_values = [];
    foreach ($tags['#attached']['html_head'] as $tag) {
      if (isset($tag[0]['#attributes']['href'])) {
        $tag_values[$tag[1]] = $tag[0]['#attributes']['href'];
      }
      elseif (isset($tag[0]['#attributes']['content'])) {
        $tag_values[$tag[1]] = $tag[0]['#attributes']['content'];
      }
    }

    // Generate the social preview.
    $canonical_url = $tag_values['canonical_url'] ?? '';
    $canonical_url_parts = parse_url($canonical_url);
    $preview_host = $canonical_url_parts['host'] ?? '';

    $form_state->set('social_metatag_preview', [
      '#theme' => 'social_metatag_preview',
      '#meta' => $tag_values,
      '#url' => $canonical_url,
      '#host' => $preview_host,
    ]);

    //Dev: uncomment for quicker debugging.
    //$element['#open'] = TRUE;

    $element['social_metatag_preview'] = [
      '#type' => 'details',
      '#title' => 'Social sharing preview',
      '#attributes' => ['class' => ['social-metatag-preview-form']],
      '#weight' => -12,
      '#open' => TRUE,
    ];

    $element['social_metatag_preview']['#attached']['library'][] = 'social_metatag_preview/social_metatag_preview';

    $element['social_metatag_preview']['preview_buttons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['social-metatag-preview-buttons']],
    ];

    $element['social_metatag_preview']['preview_buttons']['label'] = [
      '#type' => 'label',
      '#title' => $this->t('Preview'),
    ];

    $element['social_metatag_preview']['preview_buttons']['search'] = [
      '#type' => 'button',
      '#value' => $this->t('Search result'),
      '#preview_type' => 'search',
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
        'event' => 'click',
      ]
    ];

    $element['social_metatag_preview']['preview_buttons']['facebook'] = [
      '#type' => 'button',
      '#value' => $this->t('Facebook share'),
      '#preview_type' => 'facebook',
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
        'event' => 'click',
      ]
    ];

    $element['social_metatag_preview']['preview_buttons']['twitter'] = [
      '#type' => 'button',
      '#value' => $this->t('Twitter share'),
      '#preview_type' => 'twitter',
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
        'event' => 'click',
      ]
    ];

    $media_type_storage = \Drupal::entityTypeManager()->getStorage('media_type');
    $image_media_types = $media_type_storage->loadByProperties(['source' => 'image']);

    $mid_default_value = $this->imageSrcTokenToMediaId($values['image_src']);

    $element['social_metatag_preview']['mid'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => array_keys($image_media_types),
      '#title' => t('Image'),
      '#default_value' => $mid_default_value,
      '#metatag_value' => $values['image_src'],
      '#after_build' => [
        [$this, 'mediaLibraryAfterBuild'],
      ],
    ];

    $element['social_metatag_preview']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $values['title'] ?? '',
      '#maxlength' => 1024,
      '#description' => $this->t(''),
    ];

    $element['social_metatag_preview']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $values['description'] ?? '',
      '#maxlength' => 1024,
      '#description' => $this->t(''),
    ];

    return $element;
  }

  /**
   * Ajax preview callback.
   */
  public function ajaxPreview(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();

    $preview = $form_state->get('social_metatag_preview');
    $preview['#preview_type'] = $triggering_element['#preview_type'] ?? 'search';

    $dialog_options = [
      'width' => '540',
    ];

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand($triggering_element['#value'] . ' preview', $preview, $dialog_options));
    return $response;
  }

  /**
   * After build callback for media_library element.
   */
  public function mediaLibraryAfterBuild($element, &$form_state) {
    if (!empty($element['empty_selection']['#value'])) {
      $element['empty_selection']['#value'] = $this->t('@metatag_value', ['@metatag_value' => $element['#metatag_value']]);
    }
    $element['#description'] = $this->t('Override the social image by uploading or selecting an image from the media library.');
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      if (isset($value['social_metatag_preview'])) {
        $social_metatag_preview_values = $value['social_metatag_preview'];

        $title = $social_metatag_preview_values['title'] ?? '';
        $description = $social_metatag_preview_values['description'] ?? '';
        $image_src = $this->mediaIdToImageSrcToken($social_metatag_preview_values['mid'] ?? '');

        $value['basic']['title'] = $title;
        $value['basic']['description'] = $description;
        $value['advanced']['image_src'] = $image_src;

        $value['open_graph']['og_title'] = $title;
        $value['open_graph']['og_description'] = $description;
        $value['open_graph']['og_image'] = $image_src;

        $value['twitter_cards']['twitter_cards_title'] = $title;
        $value['twitter_cards']['twitter_cards_description'] = $description;
        $value['twitter_cards']['twitter_cards_image'] = $image_src;

        unset($value['social_metatag_preview']);
      }
    }

    $values = parent::massageFormValues($values, $form, $form_state);

    return $values;
  }

  protected function metatagOutput($value, $token_replacements = []) {
    $processed_value = htmlspecialchars_decode(\Drupal::token()->replace($value, $token_replacements, ['clear' => TRUE]));
    return PlainTextOutput::renderFromHtml($processed_value);
  }

  protected function mediaIdToImageSrcToken($mid) {
    if ($mid) {
      return "[social-metatag-preview:image-src:$mid]";
    }
    return "";
  }

  protected function imageSrcTokenToMediaId($image_src) {
    $tokens = \Drupal::token()->scan($image_src);
    if (!empty($tokens['social-metatag-preview'])) {
      $image_src_tokens = \Drupal::token()->findWithPrefix($tokens['social-metatag-preview'], 'image-src');
      foreach ($image_src_tokens as $mid => $original) {
        return $mid;
      }
    }
    return NULL;
  }

}
