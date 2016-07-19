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
 * @package demi\image
 *
 * @property ActiveRecord $owner
 */
class ImageUploaderBehavior extends Behavior
{
    /** @var string название атрибута, хранящего в себе имя изображения или путь к изображению */
    protected $_imageAttribute = 'image';
    /** @var string альяс директории, куда будем сохранять изображения */
    protected $_savePathAlias = '@frontend/web/images';
    /** @var string альяс корневой директории сайта (где index.php находится) */
    protected $_rootPathAlias = '@frontend/web';
    /** @var string типы файлов, которые можно загружать (нужно для валидации) */
    protected $_fileTypes = 'jpg,jpeg,gif,png';
    /** @var string Максимальный размер загружаемого изображения (байт) */
    protected $_maxFileSize = 10485760; // 10mb
    /** @var array Размеры изображений, которые необходимо создать после загрузки основного */
    protected $_imageSizes = [];
    /** @var string Имя файла, который будет отображён при условии отсутствия изображения */
    protected $_noImageBaseName = 'noimage.png';
    /** @var boolean Обязательно ли загружать изображение */
    protected $_imageRequire = false;
    /** @var array Дополнительные параметры для ImageValidator */
    protected $_imageValidatorParams = [];
    /** @var string Название субдомена backend`а, нужен для того, чтобы выводить абсолютный путь к изображению в backend`е */
    protected $_backendSubdomain = 'admin.';
    /** @var string Временное хранилище для учёта текущего, уже загруженного изображения */
    protected $_oldImage = null;
    /** @var float|null Соотношение сторон для обрезки изображения, если NULL - свободная область */
    protected $_aspectRatio = null;
    /** @var array Конфигурационный массив, который переопределяет вышеуказанные настройки */
    public $imageConfig = [];
    /** @var array Компонент для работы с изображениями (например resize изображений) */
    protected static $_imageComponent;

    public function init()
    {
        parent::init();

        $i18n = Yii::$app->i18n;
        if (!isset($i18n->translations['image-upload'])) {
            $i18n->translations['image-upload'] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'sourceLanguage' => 'en-US',
            ];
        }
    }

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
            'extensions' => $this->_fileTypes,
            'maxSize' => $this->_maxFileSize,
            'skipOnEmpty' => true,
            'tooBig' => Yii::t('image-upload', 'The image is too large, the maximum size: ') . floor($this->_maxFileSize / 1024 / 1024) . Yii::t('image-upload', 'MB'),
        ], $this->_imageValidatorParams);

        $validator = Validator::createValidator(ImageValidator::className(), $owner, $this->_imageAttribute,
            $validatorParams);
        $owner->validators->append($validator);
    }

    /**
     * Get image config param value
     *
     * @param string $paramName
     *
     * @return mixed
     */
    public function getImageConfigParam($paramName)
    {
        $name = '_' . $paramName;

        return $this->$name;
    }

    /**
     * Set new config param value
     *
     * @param string $paramName
     * @param mixed $value
     */
    public function setImageConfigParam($paramName, $value)
    {
        $name = '_' . $paramName;

        $this->$name = $value;
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

        $image = $owner->{$this->_imageAttribute};
        $image = ($image instanceof UploadedFile) ? $image :
            UploadedFile::getInstance($owner, $this->_imageAttribute);

        if ($image instanceof UploadedFile) {
            // Если было передано изоборажение - загружаем его, старое удаляем
            $new_image = static::uploadImage($image);
            if (!empty($new_image)) {
                $this->deleteImage();
                $owner->{$this->_imageAttribute} = $new_image;
            } else {
                // Если новое изображение оказалось кривое
                $owner->{$this->_imageAttribute} = $this->_oldImage;
            }
        } else {
            // Если нового изображения не было передано - вернём старое на место
            $owner->{$this->_imageAttribute} = $this->_oldImage;
        }
    }

    public function afterFind()
    {
        $owner = $this->owner;
        // Запомним текущее изображение, дабы не переписать его пустым значением
        $this->_oldImage = $owner->{$this->_imageAttribute};
    }

    /**
     * Delete image file and set|save model image field to null
     *
     * @param bool $updateDb need to update image field in DB
     */
    public function deleteImage($updateDb = false)
    {
        $owner = $this->owner;
        $DS = DIRECTORY_SEPARATOR;

        $image = str_replace('/', $DS, $this->_oldImage);

        if (!empty($image)) {
            $dirName = Yii::getAlias($this->_savePathAlias);
            // Удаляем все ресазы изображения
            foreach ($this->getImageSizes() as $prefix => $size) {
                $file_name = $dirName . $DS . static::addPrefixToFile($image, $prefix);
                @unlink($file_name);
            }
            // Remove original image
            @unlink($this->getOriginalImagePath());
        }

        // Обнуляем значение
        $owner->{$this->_imageAttribute} = null;
        $this->_oldImage = null;

        if ($updateDb) {
            $owner->update(false, [$this->_imageAttribute]);
        }

    }

    /**
     * Get image sizes
     *
     * @return array
     */
    protected function getImageSizes()
    {
        if (is_callable($this->_imageSizes)) {
            return call_user_func($this->_imageSizes, $this->owner);
        } else {
            return $this->_imageSizes;
        }
    }

    /**
     * Загружает переданное изображение в нужную директорию
     *
     * @param UploadedFile $image
     *
     * @return string путь к фото для сохранения его в базе, в случае ошибки NULL
     */
    protected function uploadImage(UploadedFile $image)
    {
        $DS = DIRECTORY_SEPARATOR;
        // Max width for uploaded original image
        $maxWidth = 1500;
        $namePart = uniqid();
        $name = $namePart . '.' . $image->extension; // Имя будущего файла
        $imageFolder = Yii::getAlias($this->_savePathAlias); // Куда загружать изображение
        // Создаём новую рандомную директорию для загрузки в неё изображений
        $rnddir = static::getRandomDir($imageFolder);
        $fullImagePath = $imageFolder . $DS . $rnddir . $DS . $name; // Полный путь к изображению
        if ($image->saveAs($fullImagePath)) {
            // Reduce image if image is very large
            $imageComponent = static::getImageComponent();
            $imageInfo = getimagesize($fullImagePath);
            $img_width = $imageInfo[0];
            if ($img_width > $maxWidth) {
                /* @var $image_o Image_GD|Image_Imagick */
                $image_o = $imageComponent->load($fullImagePath);
                $image_o->resize($maxWidth, static::getMaxHeight($maxWidth));
                $image_o->save($fullImagePath);
            }

            // Save original file
            $originalImage = $imageFolder . $DS . $rnddir . $DS . $namePart . '_original.' . $image->extension;
            @copy($fullImagePath, $originalImage);
            // Если изображение успешно сохранено - делаем ресайзные копии
            $sizes = $this->getImageSizes();
            $imageInfo = getimagesize($fullImagePath);
            $img_width = $imageInfo[0];
            $img_height = $imageInfo[1];

            // Crop image if set aspectRatio value
            if ($this->_aspectRatio) {
                $isVertical = $img_width < $img_height;

                if (!$isVertical) {
                    $width = $img_height * $this->_aspectRatio;
                    $height = $width / $this->_aspectRatio;
                } else {
                    $height = $img_width / $this->_aspectRatio;
                    $width = $height * $this->_aspectRatio;
                }
                /* @var $image_c Image_GD|Image_Imagick */
                $image_c = $imageComponent->load($fullImagePath);
                $image_c->crop($width, $height);
                $img_width = $width;
                $image_c->save($fullImagePath);
            }

            // Если изображение НЕ шире чем положено - удаляем главный размер из списка для ресайза
            if ($img_width <= $sizes['']) {
                unset($sizes['']);
            }

            // Запускаем ресайз
            $rez = static::resizeAndSave($imageFolder . $DS . $rnddir, $name, $sizes);
            // Если ресайз прошёл успешно
            if ($rez === true) {
                return $rnddir . '/' . $name;
            } else {
                // Если ресайз пошёл неправильно - удалим файлы
                foreach ($this->getImageSizes() as $size) {
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
    public static function addPrefixToFile($path, $prefix = null)
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
     * Подставляет постфикс к имени файла
     * Например addPrefixToFile("dirname/50b3d1ad130d0.png", "_normal") вернёт "dirname/50b3d1ad130d0_normal.png"
     *
     * @param string $path    путь к главному изображению
     * @param string $postfix постфикс нужного размера
     *
     * @return string путь с постфиксом
     */
    public static function addPostfixToFile($path, $postfix = null)
    {
        if ($postfix === null || $postfix == '') {
            return $path;
        }

        $parts = explode('.', $path);

        if (count($parts) === 1) {
            return $postfix . $path;
        }

        $parts[count($parts) - 2] .= $postfix;

        return implode('.', $parts);
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
        $owner = $this->owner;

        $prefix = '';
        if (Yii::$app->request instanceof \yii\web\Request) {
            $prefix = Yii::$app->request->baseUrl;
            $host = Yii::$app->request->hostInfo;
            // Если мы сейчас находимся на субдомене admin.*, то вернём абсолютный путь к картинке на frontend
            if (!empty($this->_backendSubdomain) && strpos($host, $this->_backendSubdomain)) {
                $prefix = str_replace($this->_backendSubdomain, '', $host) . $prefix;
            }
        }

        $image = $owner->{$this->_imageAttribute};
        if (empty($image)) {
            if (isset($this->getImageSizes()[$size])) {
                return $prefix . '/images/' . static::addPrefixToFile($this->_noImageBaseName, $size);
            }

            return $prefix . '/images/' . $this->_noImageBaseName;
        }

        $root = Yii::getAlias($this->_rootPathAlias); // Корень сайта
        $path = Yii::getAlias($this->_savePathAlias); // Получаем путь до папки с загрузками
        $path = str_replace($root, '', $path); // Убиаем из полного пути часть webroot
        $path = str_replace('\\', '/', $path); // Заменяем "\" на "/"
        $folder = $prefix . '/' . trim($path, '/') . '/';

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
    public static function getRandomDir($path)
    {
        $DS = DIRECTORY_SEPARATOR;
        $max_scatter = 9; // Диапазон имён директорий 0..$max_scatter
        $levels = 3; // Уровень вложенности директорий
        $dirs = [];
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
    public static function resizeAndSave($dir, $fileName, $resizeWidth)
    {
        $DS = DIRECTORY_SEPARATOR;

        // Полный путь к оригинальному изображению
        $fullPath = $dir . $DS . $fileName;

        if (!@file_exists($fullPath)) {
            // Если оригинального файла не существует - нет смысла продолжать
            return false;
        }

        try {
            $imageComponent = static::getImageComponent();
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
     * Get image component
     *
     * @return \yii\image\ImageDriver
     */
    public static function getImageComponent()
    {
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

        return $imageComponent;
    }

    /**
     * Возвращает максимальную высоту изображения относительно переданной ширины
     *
     * @param integer $width
     *
     * @return integer
     */
    public static function getMaxHeight($width)
    {
        return $width * 2;
    }

    /**
     * Return instance of current behavior
     *
     * @return self $this
     */
    public function geImageBehavior()
    {
        return $this;
    }

    /**
     * Return server path to original image src
     *
     * @return string
     */
    protected function getOriginalImagePath()
    {
        $savePath = Yii::getAlias($this->_savePathAlias);
        $image = $this->_oldImage;

        return static::addPostfixToFile($savePath . DIRECTORY_SEPARATOR . $image, '_original');
    }

    /**
     * Crop original image and resave resized images
     *
     * @param int $x
     * @param int $y
     * @param int $width
     * @param int $height
     * @param int $rotate
     *
     * @return bool
     */
    public function cropImage($x, $y, $width, $height, $rotate)
    {
        $DS = DIRECTORY_SEPARATOR;
        $savePath = Yii::getAlias($this->_savePathAlias);
        $imageSrc = $this->owner->{$this->_imageAttribute};
        $fullImagePath = $savePath . $DS . $imageSrc;

        $imageComponent = static::getImageComponent();
        /* @var $image Image_GD|Image_Imagick */
        $image = $imageComponent->load($this->getOriginalImagePath());

        $image->crop($width, $height, $x, $y);
        $image->rotate($rotate);

        $image->save($fullImagePath);

        $sizes = $this->getImageSizes();
        unset($sizes['']);

        $pathParts = explode('/', str_replace('\\', '/', $imageSrc));
        $filename = array_pop($pathParts);

        // Запускаем ресайз
        return static::resizeAndSave($savePath . $DS . implode($DS, $pathParts), $filename, $sizes);
    }
}