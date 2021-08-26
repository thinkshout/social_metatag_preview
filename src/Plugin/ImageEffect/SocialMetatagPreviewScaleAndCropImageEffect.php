<?php

namespace Drupal\social_metatag_preview\Plugin\ImageEffect;

use Drupal\Core\Image\ImageInterface;
use Drupal\image\Plugin\ImageEffect\ScaleAndCropImageEffect;

/**
 * Scales and crops an image resource without upscaling.
 *
 * Differs from the main ScaleAndCrop effect in that it will not upscale the
 * original image in order to "fit" the desired image dimensions. If either or
 * both of the original image dimensions are too small to fit the desired
 * dimensions, then instead of scaling the image up, the desired dimensions are
 * scaled down in order to fix the original image. As a result, the aspect
 * ratio of the desired dimensions is respected, but the overall size of the
 * image may be smaller.
 *
 * @see https://www.drupal.org/project/image_scale_and_crop_without_upscale
 *
 * @ImageEffect(
 *   id = "social_metatag_preview_image_scale_and_crop",
 *   label = @Translation("Scale and crop (Social Metatag Preview)"),
 *   description = @Translation("Scale and crop will maintain the aspect-ratio of the original image, then crop the larger dimension. No upscaling is performed.")
 * )
 */
class SocialMetatagPreviewScaleAndCropImageEffect extends ScaleAndCropImageEffect {

  /**
   * {@inheritdoc}
   */
  public function applyEffect(ImageInterface $image) {
    // If the image's dimensions are already equal to the target dimensions,
    // then we can skip this effect entirely.
    if ($image->getWidth() === $this->configuration['width'] && $image->getHeight() === $this->configuration['height']) {
      return TRUE;
    }

    $newTargetDimensions = $this->adjustTargetDimensionsToPreventUpscale($image->getWidth(), $image->getHeight());
    $this->configuration['width'] = $newTargetDimensions['width'];
    $this->configuration['height'] = $newTargetDimensions['height'];

    return parent::applyEffect($image);
  }

  /**
   * Adjust the target dimensions to maintain desired A/R without upscaling.
   *
   * @param int $sourceWidth
   *   The width of the source image.
   * @param int $sourceHeight
   *   The height of the source image.
   *
   * @return array
   *   The adjusted target dimensions.
   */
  private function adjustTargetDimensionsToPreventUpscale($sourceWidth, $sourceHeight) {
    $targetWidth = $this->configuration['width'];
    $targetHeight = $this->configuration['height'];

    $targetRatio = round($targetWidth / $targetHeight, 2, PHP_ROUND_HALF_UP);
    $sourceRatio = round($sourceWidth / $sourceHeight, 2, PHP_ROUND_HALF_UP);

    $transformedTargetDimensions = [
      'width' => $targetWidth,
      'height' => $targetHeight,
    ];
    // No adjustment is necessary if the the source image is larger than the
    // target dimensions. The parent ScaleAndCropImageEffect effect will
    // downscale the image and crop to fit as needed.
    if ($sourceWidth > $targetWidth && $sourceHeight > $targetHeight) {
      return $transformedTargetDimensions;
    }
    // If there's not enough image to completely fill the target dimensions,
    // then shrink the target dimensions so that it does fit. This prevents
    // us from pointlessly upscaling the image (and this reducing quality).
    // There's three cases to handle. The first if both the source width and
    // height are smaller than the target width and height.
    elseif ($sourceWidth <= $targetWidth && $sourceHeight <= $targetHeight) {
      // If the source ratio is already the same as the target ratio, then
      // modify the target dimensions to be equal to the source to prevent
      // a pointless upscale. The source image is fine as is.
      // Note that since we have access to the aspect ratio as a float,
      // the calculated source and target ratios may not be precisely the same
      // ratio, but we just want to check that they're close enough.
      if ($sourceRatio === $targetRatio) {
        $transformedTargetDimensions['width'] = $sourceWidth;
        $transformedTargetDimensions['height'] = $sourceHeight;
      }
      // Depending on how the A/Rs compare between the source and target,
      // figure out if we need to adjust the target's width or height.
      elseif ($targetRatio > $sourceRatio) {
        $transformedTargetDimensions['width'] = $sourceWidth;
        $transformedTargetDimensions['height'] = round($sourceWidth / $targetRatio, 0, PHP_ROUND_HALF_UP);
      }
      else {
        $transformedTargetDimensions['height'] = $sourceHeight;
        $transformedTargetDimensions['width'] = round($sourceHeight * $targetRatio, 0, PHP_ROUND_HALF_UP);
      }
    }
    // The second case is if just the image width is smaller than the target
    // width.
    elseif ($sourceWidth <= $targetWidth) {
      $transformedTargetDimensions['width'] = $sourceWidth;
      $transformedTargetDimensions['height'] = round($sourceWidth / $targetRatio, 0, PHP_ROUND_HALF_UP);
    }
    // And the final case is if just the image height is smaller than the
    // target height.
    elseif ($sourceHeight <= $targetHeight) {
      $transformedTargetDimensions['height'] = $sourceHeight;
      $transformedTargetDimensions['width'] = round($sourceHeight * $targetRatio, 0, PHP_ROUND_HALF_UP);
    }

    return $transformedTargetDimensions;
  }

  /**
   * {@inheritdoc}
   */
  public function transformDimensions(array &$dimensions, $uri) {
    if (!empty($dimensions['width']) && !empty($dimensions['height'])) {
      $dimensions = $this->adjustTargetDimensionsToPreventUpscale($dimensions['width'], $dimensions['height']);
    }
    else {
      // We cannot accurately calculate the target dimensions if we don't already
      // have dimensions to work from.
      $dimensions = [
        'width' => NULL,
        'height' => NULL,
      ];
    }
  }

}
