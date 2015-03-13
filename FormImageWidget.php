<?php

namespace demi\image;

use demi\image\ImageUploaderBehavior;
use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\helpers\Url;
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

    public function run()
    {
        /* @var $model ActiveRecord|ImageUploaderBehavior */
        $model = $this->model;
        /* @var $behavior ImageUploaderBehavior */
        $behavior = $model->geImageBehavior();

        $wigetId = uniqid();
        $img_hint = '<div class="hint-block">';
        $img_hint .= 'Поддерживаемые форматы: ' . $behavior->getImageConfigParam('fileTypes') . '.
	Максимальный размер файла: ' . ceil($behavior->getImageConfigParam('maxFileSize') / 1024 / 1024) . 'мб.';
        $img_hint .= '</div>';

        $imageVal = $model->getAttribute($behavior->getImageConfigParam('imageAttribute'));
        if (!$model->isNewRecord && !empty($imageVal)) {
            $img_hint .= '<div id="' . $wigetId . '">';
            $img_hint .=  Html::img($this->imageSrc) . '<br />';
            $img_hint .= Html::a('Удалить фотографию', '#',
                [
                    'onclick' => new JsExpression('
                        if (!confirm("Вы действительно хотите удалить изображение?")) {
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
                ')
                ]);
            $img_hint .= '</div>';
        }


        $imgAttr = $behavior->getImageConfigParam('imageAttribute');
        echo Html::activeFileInput($model, $imgAttr);
        echo $img_hint;
    }
} 