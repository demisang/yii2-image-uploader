yii2-image-uploader
===================

Yii2 behavior for upload image to model

Installation
------------
Add to composer.json in your project
```json
{
	"require":
	{
  		"demi/image": "dev-master"
	}
}
```
then run command
```code
php composer.phar update
```
Configuration
-------------
In model file add image uploader behavior:
```php
public function behaviors()
{
    return [
        'imageUploaderBehavior' => array(
            'class' => \demi\image\ImageUploaderBehavior::className(),
            'imageAttribute' => 'image',
            'savePathAlias' => '@frontend/web/images/products',
            'noImageBaseName' => 'noimage.png',
            'imageSizes' => array(
                '' => 1000,
                'medium_' => 270,
                'small_' => 70,
                'my_custom_size' => 25,
            ),
        ),
    ];
}
```
Usage
-----
In any view file:
```php
// big(mainly) image
Html::image($model->getImageSrc());
// or shortly
Html::image($model->imageSrc);

// medium image
Html::image($model->getImageSrc('medium_'));

// small image
Html::image($model->getImageSrc('small_'));

// image size "my_custom_size"
Html::image($model->getImageSrc('my_custom_size'));
```