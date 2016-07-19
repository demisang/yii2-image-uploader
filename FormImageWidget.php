<?php

namespace demi\image;

use Yii;
use yii\helpers\Url;
use yii\helpers\Html;
use yii\db\ActiveRecord;
use demi\cropper\Cropper;
use yii\web\JsExpression;
use yii\widgets\InputWidget;

/**
 * Виджет отображения загруженного изображения в форме
 *
 * @package demi\image
 */
class FormImageWidget extends InputWidget
{
    public $imageSrc;
    public $deleteUrl;
    public $cropUrl;
    public $cropPluginOptions = [];

    public function init()
    {
        parent::init();
        $this->registerTranslations();
    }

    public function registerTranslations()
    {
        $i18n = Yii::$app->i18n;
        if (!isset($i18n->translations['image-upload'])) {
            $i18n->translations['image-upload'] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'sourceLanguage' => 'en-US',
            ];
        }
    }

    public function run()
    {
        /* @var $model ActiveRecord|ImageUploaderBehavior */
        $model = $this->model;
        /* @var $behavior ImageUploaderBehavior */
        $behavior = $model->geImageBehavior();

        $wigetId = $this->id;
        $img_hint = '<div class="hint-block">';
        $img_hint .= Yii::t('image-upload', 'Supported formats:') . ' ' .
            $behavior->getImageConfigParam('fileTypes') . '<br />';
        $img_hint .= Yii::t('image-upload', 'Maximum file size:') . ' ' .
            ceil($behavior->getImageConfigParam('maxFileSize') / 1024 / 1024) . Yii::t('image-upload', 'MB');
        $img_hint .= '</div><!-- /.hint-block -->';

        $imageVal = $model->getAttribute($behavior->getImageConfigParam('imageAttribute'));
        if (!$model->isNewRecord && !empty($imageVal)) {
            $img_hint .= '<div id="' . $wigetId . '" class="row">';
            $img_hint .= '<div class="col-md-12">';
            $img_hint .= Html::img($this->imageSrc, ['class' => 'pull-left uploaded-image-preview']);
            // $img_hint .= '<div class="pull-left" style="margin-left: 5px;">';
            $img_hint .= '<div class="btn-group-vertical pull-left"  style="margin-left: 5px;" role="group">';
            $img_hint .= Html::a('Delete <i class="glyphicon glyphicon-trash"></i>', '#',
                [
                    'onclick' => new JsExpression('
                        if (!confirm(" ' . Yii::t('image-upload', 'Are you sure you want to delete the image?') . '")) {
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