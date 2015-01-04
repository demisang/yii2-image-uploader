<?php

namespace demi\image;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\image\drivers\Image_GD;
use yii\image\drivers\Image_Imagick;
use yii\validators\ImageValidator;
use yii\validators\RequiredValidator;
use yii\validators\Validator;
use yii\web\JsExpression;
use yii\web\UploadedFile;

/**
 * Поведение для работы с главным изображением материала
 *
 * @package demi\seo
 *
 * @property ActiveRecord $owner
 */
class ImageUploaderBehavior extends Behavior
{
    /** @var string название атрибута, хранящего в себе имя изображения или путь к изображению */
    private $_imageAttribute = 'image';
    /** @var string альяс директории, куда будем сохранять изображения */
    private $_savePathAlias = '@frontend/web/images';
    /** @var string альяс корневой директории сайта (где index.php находится) */
    private $_rootPathAlias = '@frontend/web';
    /** @var string типы файлов, которые можно загружать (нужно для валидации) */
    private $_fileTypes = 'jpg,jpeg,gif,png';
    /** @var string Максимальный размер загружаемого изображения (байт) */
    private $_maxFileSize = 10485760; // 10mb
    /** @var array Размеры изображений, которые необходимо создать после загрузки основного */
    private $_imageSizes = [];
    /** @var string Имя файла, который будет отображён при условии отсутствия изображения */
    private $_noImageBaseName = 'noimage.png';
    /** @var boolean Обязательно ли загружать изображение */
    private $_imageRequire = false;
    /** @var array Дополнительные параметры для ImageValidator */
    private $_imageValidatorParams = [];
    /** @var string Временное хранилище для учёта текущего, уже загруженного изображения */
    private $_oldImage = null;
    /** @var array Конфигурационный массив, который переопределяет вышеуказанные настройки */
    public $imageConfig = [];
    /** @var array Компонент для работы с изображениями (например resize изображений) */
    private static $_imageComponent;

    /**
     * @param ActiveRecord $owner
     */
    public function attach($owner)
    {
        parent::attach($owner);

        // Применяем конфигурационные опции
        foreach ($this->imageConfig as $key => $value) {
            $var = '_' . $key;
            $this->$var = $value;
        }

        // Вычисляем корень сайта
        if (empty($this->_rootPathAlias)) {
            $savePathParts = explode('/', $this->_savePathAlias);
            // Удаляем последнюю часть
            unset($savePathParts[count($savePathParts - 1)]);
            // Объединяем все части обратно
            $this->_rootPathAlias = implode('/', $savePathParts);
        }

        // Добавляем валидатор require
        if ($this->_imageRequire) {
            $owner->validators->append(Validator::createValidator(RequiredValidator::className(), $owner,
                $this->_imageAttribute));
        }

        // Подключаем валидатор изображения
        $validatorParams = array_merge([
            'types' => $this->_fileTypes,
            'maxSize' => $this->_maxFileSize,
            'skipOnEmpty' => true,
            'tooBig' => 'Изображение слишком велико, максимальный размер: ' . floor($this->_maxFileSize / 1024 / 1024) . ' Мб',
        ], $this->_imageValidatorParams);

        $validator = Validator::createValidator(ImageValidator::className(), $owner, $this->_imageAttribute,
            $validatorParams);
        $owner->validators->append($validator);
    }

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteImage',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    public function beforeSave()
    {
        $owner = $this->owner;

        $image = $owner->getAttribute($this->_imageAttribute);
        $image = ($image instanceof UploadedFile) ? $image :
            UploadedFile::getInstance($owner, $this->_imageAttribute);

        if ($image instanceof UploadedFile) {
            // Если было передано изоборажение - загружаем его, старое удаляем
            $new_image = static::uploadImage($image);
            if (!empty($new_image)) {
                $this->deleteImage();
                $owner->setAttribute($this->_imageAttribute, $new_image);
            } else {
                // Если новое изображение оказалось кривое
                $owner->setAttribute($this->_imageAttribute, $this->_oldImage);
            }
        } else {
            // Если нового изображения не было передано - вернём старое на место
            $owner->setAttribute($this->_imageAttribute, $this->_oldImage);
        }
    }

    public function afterFind()
    {
        $owner = $this->owner;
        // Запомним текущее изображение, дабы не переписать его пустым значением
        $this->_oldImage = $owner->getAttribute($this->_imageAttribute);
    }

    /**
     * Удаление изображения
     */
    public function deleteImage()
    {
        $owner = $this->owner;

        $DS = DIRECTORY_SEPARATOR;
        if (!empty($image)) {
            $image = str_replace('/', $DS, $this->_oldImage);
            $dirName = Yii::getAlias($this->_savePathAlias);
            // Удаляем все ресазы изображения
            foreach ($this->_imageSizes as $prefix => $size) {
                $file_name = $dirName . $DS . static::addPrefixToFile($image, $prefix);
                @unlink($file_name);
            }
        }

        // Обнуляем значение
        $owner->setAttribute($this->_imageAttribute, null);
        // Сохраняем значение в БД
        $owner->save(false, [$this->_imageAttribute]);
    }

    /**
     * Загружает переданное изображение в нужную директорию
     *
     * @param UploadedFile $image
     *
     * @return string путь к фото для сохранения его в базе, в случае ошибки NULL
     */
    private function uploadImage(UploadedFile $image)
    {
        $DS = DIRECTORY_SEPARATOR;
        $name = uniqid() . '.' . $image->extension; // Имя будущего файла
        $imageFolder = Yii::getAlias($this->_savePathAlias); // Куда загружать изображение
        // Создаём новую рандомную директорию для загрузки в неё изображений
        $rnddir = static::getRandomDir($imageFolder);
        $fullImagePath = $imageFolder . $DS . $rnddir . $DS . $name; // Полный путь к изображению
        if ($image->saveAs($fullImagePath)) {
            // Если изображение успешно сохранено - делаем ресайзные копии
            $sizes = $this->_imageSizes;
            $imageInfo = getimagesize($fullImagePath);

            // Если изображение НЕ шире чем положено - удаляем главный размер из списка для ресайза
            if ($imageInfo[0] <= $sizes['']) {
                unset($sizes['']);
            }

            // Запускаем ресайз
            $rez = static::resizeAndSave($imageFolder . $DS . $rnddir, $name, $sizes);
            // Если ресайз прошёл успешно
            if ($rez === true) {
                return $rnddir . '/' . $name;
            } else {
                // Если ресайз пошёл неправильно - удалим файлы
                foreach ($this->_imageSizes as $size) {
                    $file_name = $imageFolder . $DS . $rnddir . $DS . static::addPrefixToFile($name, $size);
                    @unlink($file_name);
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Подставляет префикс к имени файла
     * Например addPrefixToFile("dirname/50b3d1ad130d0.png", "normal_") вернёт "dirname/normal_50b3d1ad130d0.png"
     *
     * @param string $path   путь к главному изображению
     * @param string $prefix префикс нужного размера
     *
     * @return string путь с префиксом
     */
    private static function addPrefixToFile($path, $prefix = null)
    {
        if ($prefix === null || $prefix == '') {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $dir = explode('/', $path);
        $lastIndex = count($dir) - 1;
        $dir[$lastIndex] = $prefix . $dir[$lastIndex];

        return implode('/', $dir);
    }

    /**
     * Возвращает путь к картинке этой модели указанного размера
     *
     * @param string $size Префикс размера нужного изображения
     *
     * @return string путь к изображению, пригодный для Html::image()
     */
    public function getImageSrc($size = null)
    {
        /* @var $owner ActiveRecord */
        $owner = $this->owner;
        $image = $owner->getAttribute($this->_imageAttribute);
        if (empty($image)) {
            if (isset($this->_imageSizes[$size])) {
                return Yii::$app->request->baseUrl . '/images/' . static::addPrefixToFile($this->_noImageBaseName,
                    $size);
            }

            return Yii::$app->request->baseUrl . '/images/' . $this->_noImageBaseName;
        }

        $root = Yii::getAlias($this->_rootPathAlias); // Корень сайта
        $path = Yii::getAlias($this->_savePathAlias); // Получаем путь до папки с загрузками
        $path = str_replace($root, '', $path); // Убиаем из полного пути часть webroot
        $path = str_replace('\\', '/', $path); // Заменяем "\" на "/"
        $folder = Yii::$app->request->baseUrl . '/' . trim($path, '/') . '/';

        if (!empty($size)) {
            return $folder . static::addPrefixToFile($image, $size);
        } else {
            return $folder . $image;
        }
    }

    /**
     * Создаём новую директорию в указаном пути, если она не была создана ранее
     *
     * @param string $path путь, где должна быть создана новая директория
     *
     * @return string Путь к новой директории
     */
    private static function getRandomDir($path)
    {
        $DS = DIRECTORY_SEPARATOR;
        $max_scatter = 9; // Диапазон имён директорий 0..$max_scatter
        $levels = 3; // Уровень вложенности директорий
        $dirs = array();
        for ($i = 1; $i <= $levels; $i++) {
            $dirs[] = (string)rand(0, $max_scatter);
        }
        $dir_path = implode($DS, $dirs);
        $full_path = rtrim($path, '/\\') . $DS . $dir_path;

        if (!is_dir($full_path)) {
            if (YII_DEBUG) {
                mkdir($full_path, 0777, true);
            } else {
                shell_exec('mkdir -m 0777 -p ' . $full_path);
            }
            if (!YII_DEBUG && isset($dirs[0])) {
                shell_exec('chmod -R 0777 ' . $path . $DS . $dirs[0] . $DS . '*');
            }
        }

        $dir_path = str_replace('\\', '/', $dir_path);

        return ltrim($dir_path, '/');
    }

    /**
     * Ресайзим изображение
     *
     * @param string $dir        директория, где находятся изображения данной модели
     * @param string $fileName   имя файла в директории, где находятся изображения данной модели
     * @param mixed $resizeWidth integer(ширина): будет один ресайз с перезаписью файла.<br />
     *                           array: будет ресайз для каждого элемента массива.<br />
     *                           формат массива таков:
     *                           <pre>
     *                           array('prefix1'=>sizeWidth1, 'prefix2'=>sizeWidth2)
     *                           </pre>
     *
     * @return boolean В случае успеха TRUE, иначе FALSE
     * @todo Обрезка максимальной высоты вместо ошибки
     */
    private static function resizeAndSave($dir, $fileName, $resizeWidth)
    {
        $DS = DIRECTORY_SEPARATOR;

        // Полный путь к оригинальному изображению
        $fullPath = $dir . $DS . $fileName;

        if (!@file_exists($fullPath)) {
            // Если оригинального файла не существует - нет смысла продолжать
            return false;
        }

        try {
            // Получаем компонент для работы с изображениями
            if (is_object(static::$_imageComponent)) {
                $imageComponent = static::$_imageComponent;
            } else {
                $imageComponent = Yii::createObject([
                    'class' => 'yii\image\ImageDriver',
                ]);
                // Сохраняем компонент для последующей работы с ним
                static::$_imageComponent = $imageComponent;
            }
            /* @var $imageComponent \yii\image\ImageDriver */
            $image_r = $imageComponent->load($fullPath);
            /* @var $image_r Image_GD|Image_Imagick */
            if (is_array($resizeWidth)) {
                foreach ($resizeWidth as $prefix => $width) {
                    $image_r->resize($width, static::getMaxHeight($width));
                    $image_r->save($dir . $DS . $prefix . $fileName);
                }
            } else {
                $image_r->resize($resizeWidth, static::getMaxHeight($resizeWidth));
                $image_r->save($fullPath);
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Возвращает максимальную высоту изображения относительно переданной ширины
     *
     * @param integer $width
     *
     * @return integer
     */
    private static function getMaxHeight($width)
    {
        return $width * 2;
    }

    public function renderFormImageField($form = null)
    {
        $model = $this->owner;
        $img_hint = '';
        $imageVal = $model->getAttribute($this->_imageAttribute);
        if (!$model->isNewRecord && !empty($imageVal)) {
            $wigetId = uniqid();
            $img_hint .= '<div id="' . $wigetId . '">' . Html::img($model->imageSrc) . '<br />';
            $img_hint .= Html::a('Удалить фотографию', '#',
                [
                    'onclick' => new JsExpression('
                    function() {
                        if (!confirm("Вы действительно хотите удалить изображение?")) {
                            return;
                        }

                        $.ajax({
                            type: "post",
                            cache: false,
                            url: ' . Url::to(['deleteImage', 'id' => $model->getPrimaryKey()]) . ',
                            success: function() {
                                $("#' . $wigetId . '").remove();
                            }
                        });
                    };

                    return false;
                ')
                ]);
            $img_hint .= '</div>';
        }
        $img_hint = 'Минимальный размер фотографии: ' . Yii::$app->params['user_min_image_width'] .
            ' на ' . Yii::$app->params['user_min_image_height'] . ' пикселей.<br />
	Поддерживаемые форматы: ' . Yii::$app->params['user_image_formats'] . '.
	Максимальный размер файла: ' . ceil(Yii::$app->params['user_image_max_bytes'] / 1024 / 1024) . 'мб.' .
            $img_hint;
    }
}