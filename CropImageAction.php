<?php
/**
 * @copyright Copyright (c) 2018 Ivan Orlov
 * @license   https://github.com/demisang/yii2-image-uploader/blob/master/LICENSE
 * @link      https://github.com/demisang/yii2-image-uploader#readme
 * @author    Ivan Orlov <gnasimed@gmail.com>
 */

namespace demi\image;

use Yii;
use Closure;
use yii\base\Action;
use yii\base\InvalidParamException;
use yii\db\ActiveRecord;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Standalone action for crop uploaded image
 *
 * @package demi\image
 */
class CropImageAction extends Action
{
    /** @var string ClassName of AR model */
    public $modelClass;
    /** @var string Name for x-offset request param */
    public $leftParamName = 'x';
    /** @var string Name for y-offset request param */
    public $topParamName = 'y';
    /** @var string Name for width request param */
    public $widthParamName = 'width';
    /** @var string Name for height request param */
    public $heightParamName = 'height';
    /** @var string Name for rotate request param */
    public $rotateParamName = 'rotate';
    /** @var Closure|bool Closure function to check user access to delete model image */
    public $canCrop = true;
    /** @var Closure|array|string Closure function to get redirect url on after delete image */
    public $redirectUrl;
    /** @var Closure|null Closure function to get custom response on after delete image */
    public $afterCrop;

    public function run()
    {
        /* @var $model ActiveRecord|ImageUploaderBehavior */
        $model = new $this->modelClass;

        $request = Yii::$app->request;
        $pk = $model->getTableSchema()->primaryKey;
        $attributes = [];
        // forming search condition
        foreach ($pk as $primaryKey) {
            $pkValue = static::_getRequestParam($primaryKey);
            if ($pkValue === null) {
                throw new InvalidParamException('You must specify "' . $primaryKey . '" param');
            }
            $attributes[$primaryKey] = $pkValue;
        }

        $x = static::_getRequestParam($this->leftParamName, 0);
        $y = static::_getRequestParam($this->topParamName, 0);
        $width = static::_getRequestParam($this->widthParamName);
        $height = static::_getRequestParam($this->heightParamName);
        $rotate = static::_getRequestParam($this->rotateParamName);
        if ($width === null || $height === null) {
            throw new InvalidParamException("You must specify '{$this->widthParamName}' and '{$this->heightParamName}' params");
        }

        $model = $model->find()->where($attributes)->one();

        if (!$model) {
            throw new NotFoundHttpException('The requested model does not exist.');
        }

        $canCrop = $this->canCrop instanceof Closure ? call_user_func($this->canCrop, $model) : $this->canCrop;
        if (!$canCrop) {
            throw new ForbiddenHttpException('You are not allowed to crop an image');
        }

        // Image deletion
        $model->cropImage($x, $y, $width, $height, $rotate);

        // if exist custom response function
        if (is_callable($this->afterCrop)) {
            return call_user_func($this->afterCrop, $model);
        }

        $response = Yii::$app->response;
        // if is AJAX request
        if ($request->isAjax) {
            $response->getHeaders()->set('Vary', 'Accept');
            $response->format = Response::FORMAT_JSON;

            return ['status' => 'success'];
        }

        $url = $this->redirectUrl instanceof Closure ? call_user_func($this->redirectUrl, $model) : $this->redirectUrl;

        return $response->redirect($url);
    }

    /**
     * Return param by name from $_POST or $_GET. Post priority
     *
     * @param string $name
     * @param mixed|null $defaultValue
     *
     * @return array|mixed|null
     */
    private static function _getRequestParam($name, $defaultValue = null)
    {
        $value = $defaultValue;
        $request = Yii::$app->request;

        $get = $request->get($name, $defaultValue);
        $post = $request->post($name, $defaultValue);

        if ($post !== $defaultValue) {
            return $post;
        } elseif ($get !== $defaultValue) {
            return $get;
        }

        return $value;
    }
}
