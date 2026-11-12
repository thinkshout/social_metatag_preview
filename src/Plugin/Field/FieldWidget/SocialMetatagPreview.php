<?php

declare(strict_types=1);

namespace Drupal\social_metatag_preview\Plugin\Field\FieldWidget;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Token;
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
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The token service.
   */
  protected Token $tokenService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->tokenService = $container->get('token');
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
    if (!empty($entity->{$field_name}->value)) {
      $values = metatag_data_decode($entity->{$field_name}->value);
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
    if (isset($tags['#attached']['html_head']) && is_array($tags['#attached']['html_head'])) {
      foreach ($tags['#attached']['html_head'] as $tag) {
        if (isset($tag[0]['#attributes']['href'])) {
          $tag_values[$tag[1]] = $tag[0]['#attributes']['href'];
        }
        elseif (isset($tag[0]['#attributes']['content'])) {
          $tag_values[$tag[1]] = $tag[0]['#attributes']['content'];
        }
      }
    }

    // Generate the social preview.
    $canonical_url = $tag_values['canonical_url'] ?? '';
    $canonical_url_parts = $canonical_url ? parse_url($canonical_url) : [];
    $preview_host = $canonical_url_parts['host'] ?? '';

    $form_state->set('social_metatag_preview', [
      '#theme' => 'social_metatag_preview',
      '#meta' => $tag_values,
      '#url' => $canonical_url,
      '#host' => $preview_host,
    ]);

    $element['social_metatag_preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Social sharing preview'),
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
      ],
    ];

    $element['social_metatag_preview']['preview_buttons']['facebook'] = [
      '#type' => 'button',
      '#value' => $this->t('Facebook share'),
      '#preview_type' => 'facebook',
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
        'event' => 'click',
      ],
    ];

    $element['social_metatag_preview']['preview_buttons']['twitter'] = [
      '#type' => 'button',
      '#value' => $this->t('Twitter share'),
      '#preview_type' => 'twitter',
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
        'event' => 'click',
      ],
    ];

    $image_media_types = $this->entityTypeManager
      ->getStorage('media_type')
      ->loadByProperties(['source' => 'image']);

    $image_src_value = $values['image_src'] ?? '';
    $mid_default_value = $this->imageSrcTokenToMediaId($image_src_value);

    $element['social_metatag_preview']['mid'] = [
      '#type' => 'media_library',
      '#allowed_bundles' => array_keys($image_media_types),
      '#title' => $this->t('Image'),
      '#default_value' => $mid_default_value,
      '#metatag_value' => $image_src_value,
      '#after_build' => [
        [static::class, 'mediaLibraryAfterBuild'],
      ],
    ];

    $element['social_metatag_preview']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $values['title'] ?? '',
      '#maxlength' => 1024,
    ];

    $element['social_metatag_preview']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $values['description'] ?? '',
      '#maxlength' => 1024,
    ];

    return $element;
  }

  /**
   * Ajax preview callback.
   */
  public function ajaxPreview(array &$form, FormStateInterface $form_state): AjaxResponse {
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
  public static function mediaLibraryAfterBuild(array $element, FormStateInterface $form_state): array {
    $metatag_value = $element['#metatag_value'] ?? '';
    if (!empty($element['empty_selection']['#value']) && $metatag_value !== '') {
      $element['empty_selection']['#value'] = $metatag_value;
    }
    $element['#description'] = t('Override the social image by uploading or selecting an image from the media library.');
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

    return parent::massageFormValues($values, $form, $form_state);
  }

  /**
   * Render a metatag value with token replacement.
   */
  protected function metatagOutput(string $value, array $token_replacements = []): string {
    $processed_value = htmlspecialchars_decode($this->tokenService->replace($value, $token_replacements, ['clear' => TRUE]));
    return PlainTextOutput::renderFromHtml($processed_value);
  }

  /**
   * Build the social-metatag-preview image-src token from a media id.
   */
  protected function mediaIdToImageSrcToken(int|string|null $mid): string {
    if ($mid) {
      return "[social-metatag-preview:image-src:$mid]";
    }
    return '';
  }

  /**
   * Extract the media id from a social-metatag-preview image-src token.
   */
  protected function imageSrcTokenToMediaId(?string $image_src): ?int {
    if (empty($image_src)) {
      return NULL;
    }
    $tokens = $this->tokenService->scan($image_src);
    if (!empty($tokens['social-metatag-preview'])) {
      $image_src_tokens = $this->tokenService->findWithPrefix($tokens['social-metatag-preview'], 'image-src');
      foreach ($image_src_tokens as $mid => $original) {
        return (int) $mid;
      }
    }
    return NULL;
  }

}
