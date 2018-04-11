yii2-image-uploader
===================

Yii2 behavior for upload image to model

Installation
------------
Add to composer.json in your project
```json
{
    "require": {
        "yurkinx/yii2-image": "dev-master",
        "demi/image": "~1.0"
    }
}
```
then run command
```code
composer update
```

Configuration
-------------
In model file add image uploader behavior:
```php
/**
 * @inheritdoc
 */
public function behaviors()
{
    return [
        'imageUploaderBehavior' => [
            'class' => 'demi\image\ImageUploaderBehavior',
            'imageConfig' => [
                // Name of image attribute where the image will be stored
                'imageAttribute' => 'image',
                // Yii-alias to dir where will be stored subdirectories with images
                'savePathAlias' => '@frontend/web/images/products',
                // Yii-alias to root project dir, relative path to the image will exclude this part of the full path
                'rootPathAlias' => '@frontend/web',
                // Name of default image. Image placed to: webrooot/images/{noImageBaseName}
                // You must create all noimage files: noimage.jpg, medium_noimage.jpg, small_noimage.jpg, etc.
                'noImageBaseName' => 'noimage.jpg',
                // List of thumbnails sizes.
                // Format: [prefix=>max_width]
                // Thumbnails height calculated proportionally automatically
                // Prefix '' is special, it determines the max width of the main image
                'imageSizes' => [
                    '' => 1000,
                    'medium_' => 270,
                    'small_' => 70,
                    'my_custom_size' => 25,
                ],
                // This params will be passed to \yii\validators\ImageValidator
                'imageValidatorParams' => [
                    'minWidth' => 400,
                    'minHeight' => 300,
                    // Custom validation errors
                    // see more in \yii\validators\ImageValidator::init() and \yii\validators\FileValidator::init() 
                    'tooBig' => Yii::t('yii', 'The file "{file}" is too big. Its size cannot exceed {formattedLimit}.'),
                ],
                // Cropper config
                'aspectRatio' => 4 / 3, // or 16/9(wide) or 1/1(square) or any other ratio. Null - free ratio
                // default config
                'imageRequire' => false,
                'fileTypes' => 'jpg,jpeg,gif,png',
                'maxFileSize' => 10485760, // 10mb
                // If backend is located on a subdomain 'admin.', and images are uploaded to a directory
                // located in the frontend, you can set this param and then getImageSrc() will be return
                // path to image without subdomain part even in backend part
                'backendSubdomain' => 'admin.',
                // or if backend located by route '/admin/*'
                'backendRoute' => '/admin',
            ],
        ],
    ];
}
```

PHPdoc for model:
```php
/**
 * @method getImageSrc($size = null)
 * @property string $imageSrc
 */
```

Usage
-----
In any view file:
```php
// big(mainly) image
Html::img($model->getImageSrc());
// or shortly
Html::img($model->imageSrc);

// medium image
Html::img($model->getImageSrc('medium_'));

// small image
Html::img($model->getImageSrc('small_'));

// image size "my_custom_size"
Html::img($model->getImageSrc('my_custom_size'));
```

_form.php
```php
<?php $form = ActiveForm::begin([
    'options' => ['enctype' => 'multipart/form-data'],
]); ?>

<?= $form->field($model, 'image')->widget('demi\image\FormImageWidget', [
    'imageSrc' => $model->getImageSrc('medium_'),
    'deleteUrl' => ['deleteImage', 'id' => $model->getPrimaryKey()],
    'cropUrl' => ['cropImage', 'id' => $model->getPrimaryKey()],
    // cropper options https://github.com/fengyuanchen/cropper/blob/master/README.md#options
    'cropPluginOptions' => [],
    // Translated messages
    'messages' => [
        // {formats} and {formattedSize} will replaced by widget to actual values
        'formats' => Yii::t('app', 'Supported formats: {formats}'),
        'fileSize' => Yii::t('app', 'Maximum file size: {formattedSize}'),
        'deleteBtn' => Yii::t('app', 'Delete'),
        'deleteConfirmation' => Yii::t('app', 'Are you sure you want to delete the image?'),
        // Cropper
        'cropBtn' => Yii::t('app', 'Crop'),
        'cropModalTitle' => Yii::t('app', 'Select crop area and click "Crop" button'),
        'closeModalBtn' => Yii::t('app', 'Close'),
        'cropModalBtn' => Yii::t('app', 'Crop selected'),
    ],
]) ?>
```

Bonus: DELETE and CROP image actions!
-----
Add this code to you controller:
```php
/**
 * @inheritdoc
 */
public function actions()
{
    return [
        'deleteImage' => [
            'class' => 'demi\image\DeleteImageAction',
            'modelClass' => Post::className(),
            'canDelete' => function ($model) {
                    /* @var $model \yii\db\ActiveRecord */
                    return $model->user_id == Yii::$app->user->id;
                },
            'redirectUrl' => function ($model) {
                    /* @var $model \yii\db\ActiveRecord */
                    // triggered on !Yii::$app->request->isAjax, else will be returned JSON: {status: "success"}
                    return ['post/view', 'id' => $model->primaryKey];
                },
            'afterDelete' => function ($model) {
                    /* @var $model \yii\db\ActiveRecord */
                    // You can customize response by this function, e.g. change response:
                    if (Yii::$app->request->isAjax) {
                        Yii::$app->response->getHeaders()->set('Vary', 'Accept');
                        Yii::$app->response->format = yii\web\Response::FORMAT_JSON;

                        return ['status' => 'success', 'message' => 'Image deleted'];
                    } else {
                        return Yii::$app->response->redirect(['post/view', 'id' => $model->primaryKey]);
                    }
                },
        ],
        'cropImage' => [
            'class' => 'demi\image\CropImageAction',
            'modelClass' => Post::className(),
            'redirectUrl' => function ($model) {
                /* @var $model Post */
                // triggered on !Yii::$app->request->isAjax, else will be returned JSON: {status: "success"}
                return ['update', 'id' => $model->id];
            },
        ],
    ];
}
```
