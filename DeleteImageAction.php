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
 * Standalone action for delete uploaded image
 *
 * @package demi\image
 */
class DeleteImageAction extends Action
{
    /** @var string ClassName of AR model */
    public $modelClass;
    /** @var Closure|bool Closure function to check user access to delete model image */
    public $canDelete = true;
    /** @var Closure|array|string Closure function to get redirect url on after delete image */
    public $redirectUrl;
    /** @var Closure|null Closure function to get custom response on after delete image */
    public $afterDelete;

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

        $model = $model->find()->where($attributes)->one();

        if (!$model) {
            throw new NotFoundHttpException('The requested model does not exist.');
        }

        $canDelete = $this->canDelete instanceof Closure ? call_user_func($this->canDelete, $model) : $this->canDelete;
        if (!$canDelete) {
            throw new ForbiddenHttpException('You are not allowed to delete an image');
        }

        // Image deletion
        $model->deleteImage(true);

        // if exist custom response function
        if (is_callable($this->afterDelete)) {
            return call_user_func($this->afterDelete, $model);
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
