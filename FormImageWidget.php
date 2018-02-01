<?php
/**
 * @copyright Copyright (c) 2018 Ivan Orlov
 * @license   https://github.com/demisang/yii2-image-uploader/blob/master/LICENSE
 * @link      https://github.com/demisang/yii2-image-uploader#readme
 * @author    Ivan Orlov <gnasimed@gmail.com>
 */

namespace demi\image;

use Yii;
use yii\helpers\Url;
use yii\helpers\Html;
use yii\db\ActiveRecord;
use demi\cropper\Cropper;
use yii\web\JsExpression;
use yii\widgets\InputWidget;

/**
 * Show uploaded image in the form
 */
class FormImageWidget extends InputWidget
{
    public $imageSrc;
    public $deleteUrl;
    public $cropUrl;
    public $cropPluginOptions = [];
    public $messages = [];

    public function init()
    {
        parent::init();

        if (empty($this->messages['formats'])) {
            $this->messages['formats'] = 'Supported formats: {formats}';
        }
        if (empty($this->messages['fileSize'])) {
            $this->messages['fileSize'] = 'Maximum file size: {formattedSize}';
        }
        if (empty($this->messages['deleteBtn'])) {
            $this->messages['deleteBtn'] = 'Delete';
        }
        if (empty($this->messages['deleteConfirmation'])) {
            $this->messages['deleteConfirmation'] = 'Are you sure you want to delete the image?';
        }
        if (empty($this->messages['cropBtn'])) {
            $this->messages['cropBtn'] = 'Crop';
        }

        // Crop widget messages
        if (empty($this->messages['cropModalTitle'])) {
            $this->messages['cropModalTitle'] = 'Select crop area and click "Crop" button';
        }
        if (empty($this->messages['closeModalBtn'])) {
            $this->messages['closeModalBtn'] = 'Close';
        }
        if (empty($this->messages['cropModalBtn'])) {
            $this->messages['cropModalBtn'] = 'Crop selected';
        }
    }

    public function run()
    {
        /* @var $model ActiveRecord|ImageUploaderBehavior */
        $model = $this->model;
        /* @var $behavior ImageUploaderBehavior */
        $behavior = $model->getImageBehavior();

        $wigetId = $this->id;
        $img_hint = '<div class="hint-block">';
        // Yii::$app->formatter->asShortSize($this->getSizeLimit())
        $img_hint .= str_replace('{formats}', $behavior->getImageConfigParam('fileTypes'), $this->messages['formats']);
        $img_hint .= '<br />';
        $maxFileSize = $behavior->getImageConfigParam('maxFileSize');
        $img_hint .= str_replace('{formattedSize}', Yii::$app->formatter->asShortSize($maxFileSize),
            $this->messages['fileSize']);
        $img_hint .= '</div><!-- /.hint-block -->';

        $imageVal = $model->getAttribute($behavior->getImageConfigParam('imageAttribute'));
        if (!$model->isNewRecord && !empty($imageVal)) {
            $img_hint .= '<div id="' . $wigetId . '" class="row">';
            $img_hint .= '<div class="col-md-12">';
            $img_hint .= Html::img($this->imageSrc, ['class' => 'pull-left uploaded-image-preview']);
            // $img_hint .= '<div class="pull-left" style="margin-left: 5px;">';
            $img_hint .= '<div class="btn-group-vertical pull-left"  style="margin-left: 5px;" role="group">';
            $img_hint .= Html::a($this->messages['deleteBtn'] . ' <i class="glyphicon glyphicon-trash"></i>', '#',
                [
                    'onclick' => new JsExpression('
                        if (!confirm(" ' . $this->messages['deleteConfirmation'] . '")) {
                            return false;
                        }

                        $.ajax({
                            type: "post",
                            cache: false,
                            url: "' . Url::to($this->deleteUrl) . '",
                            success: function() {
                                $("#' . $wigetId . '").remove();
                            }
                        });

                        return false;
                    '),
                    'class' => 'btn btn-danger',
                ]);

            if (!empty($this->cropUrl)) {
                Yii::$app->response->headers->add('Access-Control-Allow-Origin', '*');
                $pluginOptions = $this->cropPluginOptions;
                $validatorParams = $behavior->getImageConfigParam('imageValidatorParams');
                if (isset($validatorParams['minWidth'])) {
                    $pluginOptions['minCropBoxWidth'] = $validatorParams['minWidth'];
                }
                if (isset($validatorParams['minHeight'])) {
                    $pluginOptions['minCropBoxHeight'] = $validatorParams['minHeight'];
                }

                $img_hint .= Cropper::widget([
                    'modal' => true,
                    'cropUrl' => $this->cropUrl,
                    'image' => ImageUploaderBehavior::addPostfixToFile($model->getImageSrc(), '_original'),
                    'aspectRatio' => $behavior->getImageConfigParam('aspectRatio'),
                    'pluginOptions' => $pluginOptions,
                    'messages' => $this->messages,
                    'ajaxOptions' => [
                        'success' => new JsExpression(<<<JS
function(data) {
    // Refresh image src value to show new cropped image
    var img = $("#$wigetId img.uploaded-image-preview");
    img.attr("src", img.attr("src").replace(/\?.*/, '') + "?" + new Date().getTime());
}
JS
                        ),
                    ],
                ]);
            }
            $img_hint .= '</div><!-- /.btn-group -->';
            $img_hint .= '</div><!-- /.col-md-12 -->';
            $img_hint .= '</div><!-- /.row -->';
        }

        $imgAttr = $behavior->getImageConfigParam('imageAttribute');

        return Html::activeFileInput($model, $imgAttr) . $img_hint;
    }
}
