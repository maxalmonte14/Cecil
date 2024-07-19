<?php

declare(strict_types=1);

/*
 * This file is part of Cecil.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Assets;

use Cecil\Assets\Image\Optimizer;
use Cecil\Builder;
use Cecil\Collection\Page\Page;
use Cecil\Config;
use Cecil\Exception\ConfigException;
use Cecil\Exception\RuntimeException;
use Cecil\Util;
use MatthiasMullie\Minify;
use ScssPhp\ScssPhp\Compiler;
use wapmorgan\Mp3Info\Mp3Info;

class Asset implements \ArrayAccess
{
    /** @var Builder */
    protected $builder;

    /** @var Config */
    protected $config;

    /** @var array */
    protected $data = [];

    /** @var bool */
    protected $fingerprinted = false;

    /** @var bool */
    protected $compiled = false;

    /** @var bool */
    protected $minified = false;

    /** @var bool */
    protected $optimize = false;

    /** @var bool */
    protected $ignore_missing = false;

    /**
     * Creates an Asset from a file path, an array of files path or an URL.
     *
     * @param Builder      $builder
     * @param string|array $paths
     * @param array|null   $options e.g.: ['fingerprint' => true, 'minify' => true, 'filename' => '', 'ignore_missing' => false]
     *
     * @throws RuntimeException
     */
    public function __construct(Builder $builder, string|array $paths, array|null $options = null)
    {
        $this->builder = $builder;
        $this->config = $builder->getConfig();
        $paths = \is_array($paths) ? $paths : [$paths];
        array_walk($paths, function ($path) {
            if (!\is_string($path)) {
                throw new RuntimeException(sprintf('The path of an asset must be a string ("%s" given).', \gettype($path)));
            }
            if (empty($path)) {
                throw new RuntimeException('The path of an asset can\'t be empty.');
            }
            if (substr($path, 0, 2) == '..') {
                throw new RuntimeException(sprintf('The path of asset "%s" is wrong: it must be directly relative to "assets" or "static" directory, or a remote URL.', $path));
            }
        });
        $this->data = [
            'file'           => '',    // absolute file path
            'files'          => [],    // array of files path (if bundle)
            'filename'       => '',    // filename
            'path_source'    => '',    // public path to the file, before transformations
            'path'           => '',    // public path to the file, after transformations
            'url'            => null,  // URL of a remote image
            'missing'        => false, // if file not found, but missing ollowed 'missing' is true
            'ext'            => '',    // file extension
            'type'           => '',    // file type (e.g.: image, audio, video, etc.)
            'subtype'        => '',    // file media type (e.g.: image/png, audio/mp3, etc.)
            'size'           => 0,     // file size (in bytes)
            'content_source' => '',    // file content, before transformations
            'content'        => '',    // file content, after transformations
            'width'          => 0,     // width (in pixels) in case of an image
            'height'         => 0,     // height (in pixels) in case of an image
            'exif'           => [],    // exif data
        ];

        // handles options
        $fingerprint = (bool) $this->config->get('assets.fingerprint.enabled');
        $minify = (bool) $this->config->get('assets.minify.enabled');
        $optimize = (bool) $this->config->get('assets.images.optimize.enabled');
        $filename = '';
        $ignore_missing = false;
        $remote_fallback = null;
        $force_slash = true;
        extract(\is_array($options) ? $options : [], EXTR_IF_EXISTS);
        $this->ignore_missing = $ignore_missing;

        // fill data array with file(s) informations
        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $cacheKey = sprintf('%s__%s', $filename ?: implode('_', $paths), $this->builder->getVersion());
        if (!$cache->has($cacheKey)) {
            $pathsCount = \count($paths);
            $file = [];
            for ($i = 0; $i < $pathsCount; $i++) {
                // loads file(s)
                $file[$i] = $this->loadFile($paths[$i], $ignore_missing, $remote_fallback, $force_slash);
                // bundle: same type only
                if ($i > 0) {
                    if ($file[$i]['type'] != $file[$i - 1]['type']) {
                        throw new RuntimeException(sprintf('Asset bundle type error (%s != %s).', $file[$i]['type'], $file[$i - 1]['type']));
                    }
                }
                // missing allowed = empty path
                if ($file[$i]['missing']) {
                    $this->data['missing'] = true;
                    $this->data['path'] = $file[$i]['path'];

                    continue;
                }
                // set data
                $this->data['size'] += $file[$i]['size'];
                $this->data['content_source'] .= $file[$i]['content'];
                $this->data['content'] .= $file[$i]['content'];
                if ($i == 0) {
                    $this->data['file'] = $file[$i]['filepath'];
                    $this->data['filename'] = $file[$i]['path'];
                    $this->data['path_source'] = $file[$i]['path'];
                    $this->data['path'] = $file[$i]['path'];
                    $this->data['url'] = $file[$i]['url'];
                    $this->data['ext'] = $file[$i]['ext'];
                    $this->data['type'] = $file[$i]['type'];
                    $this->data['subtype'] = $file[$i]['subtype'];
                    if ($this->data['type'] == 'image') {
                        $this->data['width'] = $this->getWidth();
                        $this->data['height'] = $this->getHeight();
                        if ($this->data['subtype'] == 'image/jpeg') {
                            $this->data['exif'] = Util\File::readExif($file[$i]['filepath']);
                        }
                    }
                    // bundle: default filename
                    if ($pathsCount > 1 && empty($filename)) {
                        switch ($this->data['ext']) {
                            case 'scss':
                            case 'css':
                                $filename = '/styles.css';
                                break;
                            case 'js':
                                $filename = '/scripts.js';
                                break;
                            default:
                                throw new RuntimeException(sprintf('Asset bundle supports %s files only.', '.scss, .css and .js'));
                        }
                    }
                    // bundle: filename and path
                    if (!empty($filename)) {
                        $this->data['filename'] = $filename;
                        $this->data['path'] = '/' . ltrim($filename, '/');
                    }
                }
                // bundle: files path
                $this->data['files'][] = $file[$i]['filepath'];
            }
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        // fingerprinting
        if ($fingerprint) {
            $this->fingerprint();
        }
        // compiling (Sass files)
        if ((bool) $this->config->get('assets.compile.enabled')) {
            $this->compile();
        }
        // minifying (CSS and JavScript files)
        if ($minify) {
            $this->minify();
        }
        // optimizing (images files)
        if ($optimize) {
            $this->optimize = true;
        }
    }

    /**
     * Returns path.
     *
     * @throws RuntimeException
     */
    public function __toString(): string
    {
        try {
            $this->save();
        } catch (RuntimeException $e) {
            $this->builder->getLogger()->error($e->getMessage());
        }

        if ($this->isImageInCdn()) {
            return $this->buildImageCdnUrl();
        }

        if ($this->builder->getConfig()->get('canonicalurl')) {
            return (string) new Url($this->builder, $this->data['path'], ['canonical' => true]);
        }

        return $this->data['path'];
    }

    /**
     * Fingerprints a file.
     */
    public function fingerprint(): self
    {
        if ($this->fingerprinted) {
            return $this;
        }

        $fingerprint = hash('md5', $this->data['content_source']);
        $this->data['path'] = preg_replace(
            '/\.' . $this->data['ext'] . '$/m',
            ".$fingerprint." . $this->data['ext'],
            $this->data['path']
        );

        $this->fingerprinted = true;

        return $this;
    }

    /**
     * Compiles a SCSS.
     *
     * @throws RuntimeException
     */
    public function compile(): self
    {
        if ($this->compiled) {
            return $this;
        }

        if ($this->data['ext'] != 'scss') {
            return $this;
        }

        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $cacheKey = $cache->createKeyFromAsset($this, ['compiled']);
        if (!$cache->has($cacheKey)) {
            $scssPhp = new Compiler();
            $importDir = [];
            $importDir[] = Util::joinPath($this->config->getStaticPath());
            $importDir[] = Util::joinPath($this->config->getAssetsPath());
            $scssDir = $this->config->get('assets.compile.import') ?? [];
            $themes = $this->config->getTheme() ?? [];
            foreach ($scssDir as $dir) {
                $importDir[] = Util::joinPath($this->config->getStaticPath(), $dir);
                $importDir[] = Util::joinPath($this->config->getAssetsPath(), $dir);
                $importDir[] = Util::joinPath(\dirname($this->data['file']), $dir);
                foreach ($themes as $theme) {
                    $importDir[] = Util::joinPath($this->config->getThemeDirPath($theme, "static/$dir"));
                    $importDir[] = Util::joinPath($this->config->getThemeDirPath($theme, "assets/$dir"));
                }
            }
            $scssPhp->setImportPaths(array_unique($importDir));
            // source map
            if ($this->builder->isDebug() && (bool) $this->config->get('assets.compile.sourcemap')) {
                $importDir = [];
                $assetDir = (string) $this->config->get('assets.dir');
                $assetDirPos = strrpos($this->data['file'], DIRECTORY_SEPARATOR . $assetDir . DIRECTORY_SEPARATOR);
                $fileRelPath = substr($this->data['file'], $assetDirPos + 8);
                $filePath = Util::joinFile($this->config->getOutputPath(), $fileRelPath);
                $importDir[] = \dirname($filePath);
                foreach ($scssDir as $dir) {
                    $importDir[] = Util::joinFile($this->config->getOutputPath(), $dir);
                }
                $scssPhp->setImportPaths(array_unique($importDir));
                $scssPhp->setSourceMap(Compiler::SOURCE_MAP_INLINE);
                $scssPhp->setSourceMapOptions([
                    'sourceMapBasepath' => Util::joinPath($this->config->getOutputPath()),
                    'sourceRoot'        => '/',
                ]);
            }
            // output style
            $outputStyles = ['expanded', 'compressed'];
            $outputStyle = strtolower((string) $this->config->get('assets.compile.style'));
            if (!\in_array($outputStyle, $outputStyles)) {
                throw new ConfigException(sprintf('"%s" value must be "%s".', 'assets.compile.style', implode('" or "', $outputStyles)));
            }
            $scssPhp->setOutputStyle($outputStyle);
            // variables
            $variables = $this->config->get('assets.compile.variables') ?? [];
            if (!empty($variables)) {
                $variables = array_map('ScssPhp\ScssPhp\ValueConverter::parseValue', $variables);
                $scssPhp->replaceVariables($variables);
            }
            // update data
            $this->data['path'] = preg_replace('/sass|scss/m', 'css', $this->data['path']);
            $this->data['ext'] = 'css';
            $this->data['type'] = 'text';
            $this->data['subtype'] = 'text/css';
            $this->data['content'] = $scssPhp->compileString($this->data['content'])->getCss();
            $this->data['size'] = \strlen($this->data['content']);
            $this->compiled = true;
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        return $this;
    }

    /**
     * Minifying a CSS or a JS.
     *
     * @throws RuntimeException
     */
    public function minify(): self
    {
        // disable minify to preserve inline source map
        if ($this->builder->isDebug() && (bool) $this->config->get('assets.compile.sourcemap')) {
            return $this;
        }

        if ($this->minified) {
            return $this;
        }

        if ($this->data['ext'] == 'scss') {
            $this->compile();
        }

        if ($this->data['ext'] != 'css' && $this->data['ext'] != 'js') {
            return $this;
        }

        if (substr($this->data['path'], -8) == '.min.css' || substr($this->data['path'], -7) == '.min.js') {
            $this->minified;

            return $this;
        }

        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $cacheKey = $cache->createKeyFromAsset($this, ['minified']);
        if (!$cache->has($cacheKey)) {
            switch ($this->data['ext']) {
                case 'css':
                    $minifier = new Minify\CSS($this->data['content']);
                    break;
                case 'js':
                    $minifier = new Minify\JS($this->data['content']);
                    break;
                default:
                    throw new RuntimeException(sprintf('Not able to minify "%s".', $this->data['path']));
            }
            $this->data['path'] = preg_replace(
                '/\.' . $this->data['ext'] . '$/m',
                '.min.' . $this->data['ext'],
                $this->data['path']
            );
            $this->data['content'] = $minifier->minify();
            $this->data['size'] = \strlen($this->data['content']);
            $this->minified = true;
            $cache->set($cacheKey, $this->data);
        }
        $this->data = $cache->get($cacheKey);

        return $this;
    }

    /**
     * Optimizing an image.
     */
    public function optimize(string $filepath): self
    {
        if ($this->data['type'] != 'image') {
            return $this;
        }

        $quality = $this->config->get('assets.images.quality') ?? 75;
        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $tags = ["q$quality", 'optimized'];
        if ($this->data['width']) {
            array_unshift($tags, "{$this->data['width']}x");
        }
        $cacheKey = $cache->createKeyFromAsset($this, $tags);
        if (!$cache->has($cacheKey)) {
            $message = $filepath;
            $sizeBefore = filesize($filepath);
            Optimizer::create($quality)->optimize($filepath);
            $sizeAfter = filesize($filepath);
            if ($sizeAfter < $sizeBefore) {
                $message = sprintf(
                    '%s (%s Ko -> %s Ko)',
                    $message,
                    ceil($sizeBefore / 1000),
                    ceil($sizeAfter / 1000)
                );
            }
            $this->data['content'] = Util\File::fileGetContents($filepath);
            $this->data['size'] = $sizeAfter;
            $cache->set($cacheKey, $this->data);
            $this->builder->getLogger()->debug(sprintf('Asset "%s" optimized', $message));
        }
        $this->data = $cache->get($cacheKey, $this->data);

        return $this;
    }

    /**
     * Resizes an image with a new $width.
     *
     * @throws RuntimeException
     */
    public function resize(int $width): self
    {
        if ($this->data['missing']) {
            throw new RuntimeException(sprintf('Not able to resize "%s": file not found.', $this->data['path']));
        }
        if ($this->data['type'] != 'image') {
            throw new RuntimeException(sprintf('Not able to resize "%s": not an image.', $this->data['path']));
        }
        if ($width >= $this->data['width']) {
            return $this;
        }

        $assetResized = clone $this;
        $assetResized->data['width'] = $width;

        if ($this->isImageInCdn()) {
            return $assetResized; // returns asset with the new width only: CDN do the rest of the job
        }

        $quality = $this->config->get('assets.images.quality') ?? 75;
        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $cacheKey = $cache->createKeyFromAsset($assetResized, ["{$width}x", "q$quality"]);
        if (!$cache->has($cacheKey)) {
            $assetResized->data['content'] = Image::resize($assetResized, $width, $quality);
            $assetResized->data['path'] = '/' . Util::joinPath(
                (string) $this->config->get('assets.target'),
                (string) $this->config->get('assets.images.resize.dir'),
                (string) $width,
                $assetResized->data['path']
            );
            $assetResized->data['height'] = $assetResized->getHeight();
            $assetResized->data['size'] = \strlen($assetResized->data['content']);

            $cache->set($cacheKey, $assetResized->data);
        }
        $assetResized->data = $cache->get($cacheKey);

        return $assetResized;
    }

    /**
     * Converts an image asset to WebP format.
     *
     * @throws RuntimeException
     */
    public function webp(?int $quality = null): self
    {
        if ($this->data['type'] != 'image') {
            throw new RuntimeException(sprintf('Not able to convert "%s" (%s) to WebP: not an image.', $this->data['path'], $this->data['type']));
        }

        if ($quality === null) {
            $quality = (int) $this->config->get('assets.images.quality') ?? 75;
        }

        $assetWebp = clone $this;
        $format = 'webp';
        $assetWebp['ext'] = $format;

        if ($this->isImageInCdn()) {
            return $assetWebp; // returns the asset with the new extension only: CDN do the rest of the job
        }

        $cache = new Cache($this->builder, (string) $this->builder->getConfig()->get('cache.assets.dir'));
        $tags = ["q$quality"];
        if ($this->data['width']) {
            array_unshift($tags, "{$this->data['width']}x");
        }
        $cacheKey = $cache->createKeyFromAsset($assetWebp, $tags);
        if (!$cache->has($cacheKey)) {
            $assetWebp->data['content'] = Image::convert($assetWebp, $format, $quality);
            $assetWebp->data['path'] = preg_replace('/\.' . $this->data['ext'] . '$/m', ".$format", $this->data['path']);
            $assetWebp->data['subtype'] = "image/$format";
            $assetWebp->data['size'] = \strlen($assetWebp->data['content']);

            $cache->set($cacheKey, $assetWebp->data);
        }
        $assetWebp->data = $cache->get($cacheKey);

        return $assetWebp;
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!\is_null($offset)) {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    /**
     * Implements \ArrayAccess.
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->data[$offset]) ? $this->data[$offset] : null;
    }

    /**
     * Hashing content of an asset with the specified algo, sha384 by default.
     * Used for SRI (Subresource Integrity).
     *
     * @see https://developer.mozilla.org/fr/docs/Web/Security/Subresource_Integrity
     */
    public function getIntegrity(string $algo = 'sha384'): string
    {
        return sprintf('%s-%s', $algo, base64_encode(hash($algo, $this->data['content'], true)));
    }

    /**
     * Returns MP3 file infos.
     *
     * @see https://github.com/wapmorgan/Mp3Info
     */
    public function getAudio(): Mp3Info
    {
        if ($this->data['type'] !== 'audio') {
            throw new RuntimeException(sprintf('Not able to get audio infos of "%s".', $this->data['path']));
        }

        return new Mp3Info($this->data['file']);
    }

    /**
     * Returns MP4 file infos.
     *
     * @see https://github.com/clwu88/php-read-mp4info
     */
    public function getVideo(): array
    {
        if ($this->data['type'] !== 'video') {
            throw new RuntimeException(sprintf('Not able to get video infos of "%s".', $this->data['path']));
        }

        return \Clwu\Mp4::getInfo($this->data['file']);
    }

    /**
     * Returns the Data URL (encoded in Base64).
     *
     * @throws RuntimeException
     */
    public function dataurl(): string
    {
        if ($this->data['type'] == 'image' && !Image::isSVG($this)) {
            return Image::getDataUrl($this, $this->config->get('assets.images.quality') ?? 75);
        }

        return sprintf('data:%s;base64,%s', $this->data['subtype'], base64_encode($this->data['content']));
    }

    /**
     * Saves file.
     * Note: a file from `static/` with the same name will NOT be overridden.
     *
     * @throws RuntimeException
     */
    public function save(): void
    {
        $filepath = Util::joinFile($this->config->getOutputPath(), $this->data['path']);
        if (!$this->builder->getBuildOptions()['dry-run'] && !Util\File::getFS()->exists($filepath)) {
            try {
                Util\File::getFS()->dumpFile($filepath, $this->data['content']);
                $this->builder->getLogger()->debug(sprintf('Asset "%s" saved', $filepath));
                if ($this->optimize) {
                    $this->optimize($filepath);
                }
            } catch (\Symfony\Component\Filesystem\Exception\IOException) {
                if (!$this->ignore_missing) {
                    throw new RuntimeException(sprintf('Can\'t save asset "%s".', $filepath));
                }
            }
        }
    }

    /**
     * Is Asset is an image in CDN.
     *
     * @return bool
     */
    public function isImageInCdn()
    {
        if ($this->data['type'] != 'image' || (bool) $this->config->get('assets.images.cdn.enabled') !== true || (Image::isSVG($this) && (bool) $this->config->get('assets.images.cdn.svg') !== true)) {
            return false;
        }
        // remote image?
        if ($this->data['url'] !== null && (bool) $this->config->get('assets.images.cdn.remote') !== true) {
            return false;
        }

        return true;
    }

    /**
     * Load file data.
     *
     * @throws RuntimeException
     */
    private function loadFile(string $path, bool $ignore_missing = false, ?string $remote_fallback = null, bool $force_slash = true): array
    {
        $file = [
            'url' => null,
        ];

        try {
            $filePath = $this->findFile($path, $remote_fallback);
        } catch (RuntimeException $e) {
            if ($ignore_missing) {
                $file['path'] = $path;
                $file['missing'] = true;

                return $file;
            }

            throw new RuntimeException(sprintf('Can\'t load asset file "%s" (%s).', $path, $e->getMessage()));
        }

        if (Util\Url::isUrl($path)) {
            $file['url'] = $path;
            $path = Util::joinPath(
                (string) $this->config->get('assets.target'),
                Util\File::getFS()->makePathRelative($filePath, $this->config->getCacheAssetsRemotePath())
            );
            // remote_fallback in assets/ ont in cache/assets/remote/
            if (substr(Util\File::getFS()->makePathRelative($filePath, $this->config->getCacheAssetsRemotePath()), 0, 2) == '..') {
                $path = Util::joinPath(
                    (string) $this->config->get('assets.target'),
                    Util\File::getFS()->makePathRelative($filePath, $this->config->getAssetsPath())
                );
            }
            $force_slash = true;
        }
        if ($force_slash) {
            $path = '/' . ltrim($path, '/');
        }

        list($type, $subtype) = Util\File::getMimeType($filePath);
        $content = Util\File::fileGetContents($filePath);

        $file['filepath'] = $filePath;
        $file['path'] = $path;
        $file['ext'] = pathinfo($path)['extension'] ?? '';
        $file['type'] = $type;
        $file['subtype'] = $subtype;
        $file['size'] = filesize($filePath);
        $file['content'] = $content;
        $file['missing'] = false;

        return $file;
    }

    /**
     * Try to find the file:
     *   1. remote (if $path is a valid URL)
     *   2. in static/
     *   3. in themes/<theme>/static/
     * Returns local file path or throw an exception.
     *
     * @throws RuntimeException
     */
    private function findFile(string $path, ?string $remote_fallback = null): string
    {
        // in case of remote file: save it and returns cached file path
        if (Util\Url::isUrl($path)) {
            $url = $path;
            $urlHost = parse_url($path, PHP_URL_HOST);
            $urlPath = parse_url($path, PHP_URL_PATH);
            $urlQuery = parse_url($path, PHP_URL_QUERY);
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            // Google Fonts hack
            if (Util\Str::endsWith($urlPath, '/css') || Util\Str::endsWith($urlPath, '/css2')) {
                $extension = 'css';
            }
            $relativePath = Page::slugify(sprintf(
                '%s%s%s%s',
                $urlHost,
                $this->sanitize($urlPath),
                $urlQuery ? "-$urlQuery" : '',
                $urlQuery && $extension ? ".$extension" : ''
            ));
            $filePath = Util::joinFile($this->config->getCacheAssetsRemotePath(), $relativePath);
            // not already in cache
            if (!file_exists($filePath)) {
                try {
                    if (!Util\Url::isRemoteFileExists($url)) {
                        throw new RuntimeException(sprintf('File "%s" doesn\'t exists', $url));
                    }
                    if (false === $content = Util\File::fileGetContents($url, true)) {
                        throw new RuntimeException(sprintf('Can\'t get content of file "%s".', $url));
                    }
                    if (\strlen($content) <= 1) {
                        throw new RuntimeException(sprintf('File "%s" is empty.', $url));
                    }
                } catch (RuntimeException $e) {
                    // is there a fallback in assets/
                    if ($remote_fallback) {
                        $filePath = Util::joinFile($this->config->getAssetsPath(), $remote_fallback);
                        if (Util\File::getFS()->exists($filePath)) {
                            return $filePath;
                        }
                        throw new RuntimeException(sprintf('Fallback file "%s" doesn\'t exists.', $filePath));
                    }

                    throw new RuntimeException($e->getMessage());
                }
                if (false === $content = Util\File::fileGetContents($url, true)) {
                    throw new RuntimeException(sprintf('Can\'t get content of "%s"', $url));
                }
                if (\strlen($content) <= 1) {
                    throw new RuntimeException(sprintf('Asset at "%s" is empty', $url));
                }
                // put file in cache
                Util\File::getFS()->dumpFile($filePath, $content);
            }

            return $filePath;
        }

        // checks in assets/
        $filePath = Util::joinFile($this->config->getAssetsPath(), $path);
        if (Util\File::getFS()->exists($filePath)) {
            return $filePath;
        }

        // checks in each themes/<theme>/assets/
        foreach ($this->config->getTheme() as $theme) {
            $filePath = Util::joinFile($this->config->getThemeDirPath($theme, 'assets'), $path);
            if (Util\File::getFS()->exists($filePath)) {
                return $filePath;
            }
        }

        // checks in static/
        $filePath = Util::joinFile($this->config->getStaticTargetPath(), $path);
        if (Util\File::getFS()->exists($filePath)) {
            return $filePath;
        }

        // checks in each themes/<theme>/static/
        foreach ($this->config->getTheme() as $theme) {
            $filePath = Util::joinFile($this->config->getThemeDirPath($theme, 'static'), $path);
            if (Util\File::getFS()->exists($filePath)) {
                return $filePath;
            }
        }

        throw new RuntimeException(sprintf('Can\'t find file "%s".', $path));
    }

    /**
     * Returns the width of an image/SVG.
     *
     * @throws RuntimeException
     */
    private function getWidth(): int
    {
        if ($this->data['type'] != 'image') {
            return 0;
        }
        if (Image::isSVG($this) && false !== $svg = Image::getSvgAttributes($this)) {
            return (int) $svg->width;
        }
        if (false === $size = $this->getImageSize()) {
            throw new RuntimeException(sprintf('Not able to get width of "%s".', $this->data['path']));
        }

        return $size[0];
    }

    /**
     * Returns the height of an image/SVG.
     *
     * @throws RuntimeException
     */
    private function getHeight(): int
    {
        if ($this->data['type'] != 'image') {
            return 0;
        }
        if (Image::isSVG($this) && false !== $svg = Image::getSvgAttributes($this)) {
            return (int) $svg->height;
        }
        if (false === $size = $this->getImageSize()) {
            throw new RuntimeException(sprintf('Not able to get height of "%s".', $this->data['path']));
        }

        return $size[1];
    }

    /**
     * Returns image size informations.
     *
     * @see https://www.php.net/manual/function.getimagesize.php
     *
     * @return array|false
     */
    private function getImageSize()
    {
        if (!$this->data['type'] == 'image') {
            return false;
        }

        try {
            if (false === $size = getimagesizefromstring($this->data['content'])) {
                return false;
            }
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf('Handling asset "%s" failed: "%s"', $this->data['path_source'], $e->getMessage()));
        }

        return $size;
    }

    /**
     * Replaces some characters by '_'.
     */
    private function sanitize(string $string): string
    {
        return str_replace(['<', '>', ':', '"', '\\', '|', '?', '*'], '_', $string);
    }

    /**
     * Builds CDN image URL.
     */
    private function buildImageCdnUrl(): string
    {
        return str_replace(
            [
                '%account%',
                '%image_url%',
                '%width%',
                '%quality%',
                '%format%',
            ],
            [
                $this->config->get('assets.images.cdn.account'),
                ltrim($this->data['url'] ?? (string) new Url($this->builder, $this->data['path'], ['canonical' => $this->config->get('assets.images.cdn.canonical') ?? true]), '/'),
                $this->data['width'],
                $this->config->get('assets.images.quality') ?? 75,
                $this->data['ext'],
            ],
            (string) $this->config->get('assets.images.cdn.url')
        );
    }
}
