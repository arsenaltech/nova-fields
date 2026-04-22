<?php

namespace R64\NovaFields\Http\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait GetFiles
{
    use FileFunctions;

    /**
     * Cloud disks.
     *
     * @var array
     */
    protected $cloudDisks = [
        's3', 'google', 's3-cached',
    ];

    /**
     * @param $folder
     * @param $order
     * @param $filter
     */
    public function getFiles($folder, $order, $filter = false)
    {
        $cacheTime = config('filemanager.cache', false);

        if ($cacheTime !== false) {
            $cacheKey = 'filemanager_' . md5($this->disk . '_' . $folder);
            $filesData = cache()->remember($cacheKey, $cacheTime, function () use ($folder) {
                return $this->listContentsAsArray($folder);
            });
        } else {
            $filesData = $this->listContentsAsArray($folder);
        }

        $files = [];

        foreach ($filesData as $file) {
            $fileData = $this->getFileData($file);

            if ($fileData) {
                $files[] = $fileData;
            }
        }

        $files = collect($files);

        if ($filter != false) {
            $files = $this->filterData($files, $filter);
        }

        return $this->orderData($files, $order, config('filemanager.direction', 'asc'));
    }

    /**
     * @param $file
     */
    public function getFileData($file)
    {
        if (! $this->isDot($file)
            && ! $this->exceptExtensions->contains($file['extension'])
            && ! $this->exceptFolders->contains($file['basename'])
            && ! $this->exceptFiles->contains($file['basename'])
            && $this->accept($file)) {

            $id = $this->generateId($file);

            // Get file type from extension only (NO storage calls)
            $mimeType = $this->getFileTypeFromExtension($file['extension'], $file['type']);

            $fileInfo = [
                'id'         => $id,
                'name'       => trim($file['basename']),
                'path'       => $this->cleanSlashes($file['path']),
                'type'       => $file['type'],
                'mime'       => $mimeType,
                'ext'        => $file['extension'] ?: false,
                'size'       => $file['size'] ?? 0,
                'size_human' => ($file['size'] ?? 0) > 0 ? $this->formatBytes($file['size'], 0) : 0,
                'thumb'      => $this->getThumbUrl($file, $mimeType),
                'asset'      => $this->getAssetUrl($file),
                'can'        => true,
                'loading'    => false,
            ];

            if (isset($file['timestamp']) && $file['timestamp']) {
                $fileInfo['last_modification'] = $file['timestamp'];
                $fileInfo['date'] = $this->modificationDate($file['timestamp']);
            }

            // Only get dimensions for images and only if needed
            if ($mimeType == 'image' && $this->disk === 'public') {
                [$width, $height] = $this->getImageDimesions($file);
                if ($width !== false) {
                    $fileInfo['dimensions'] = $width.'x'.$height;
                }
            }

            if ($fileInfo['type'] == 'dir') {
                if (! $this->checkShouldHideFolder($fileInfo['path'])) {
                    return false;
                }
            }

            return (object) $fileInfo;
        }

        return false;
    }

    /**
     * Get the base URL prefix for cloud storage (cached, minimal API calls).
     *
     * @return string
     */
    protected function getCloudUrlPrefix(): string
    {
        static $cloudUrlPrefix = null;
        if ($cloudUrlPrefix === null) {
            $cloudUrlPrefix = Str::beforeLast($this->storage->url('____dummy____'), '/____dummy____');
        }
        return $cloudUrlPrefix;
    }

    /**
     * Get asset URL safely (minimal API calls).
     *
     * @param array $file
     * @return string
     */
    protected function getAssetUrl(array $file): string
    {
        try {
            if ($file['type'] === 'dir' || empty($file['path'])) {
                return '';
            }

            if (in_array($this->disk, $this->cloudDisks)) {
                return $this->getCloudUrlPrefix() . '/' . ltrim($file['path'], '/');
            }

            return $this->cleanSlashes($this->getAppend() . '/' . ($file['path'] ?? ''));
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get thumbnail URL without making storage API calls.
     *
     * @param array $file
     * @param string $mimeType
     * @return string|false
     */
    protected function getThumbUrl(array $file, string $mimeType): string|false
    {
        if ($file['type'] === 'dir' || empty($file['path'])) {
            return false;
        }

        // If it's an image, return the URL directly
        if ($mimeType === 'image') {
            try {
                if (in_array($this->disk, $this->cloudDisks)) {
                    return $this->getCloudUrlPrefix() . '/' . ltrim($file['path'], '/');
                }
                return $this->storage->url($file['path']);
            } catch (\Exception $e) {
                return $this->currentPath . '/' . ($file['basename'] ?? '');
            }
        }

        // Return placeholder icon for other file types
        try {
            $fileType = new FileTypesImages();
            // Pass a dummy mime string based on type
            $dummyMime = $this->getDummyMimeType($mimeType);
            return $fileType->getImage($dummyMime);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get dummy mime type string for icon lookup.
     *
     * @param string $type
     * @return string
     */
    protected function getDummyMimeType($type)
    {
        $mimeMap = [
            'pdf' => 'application/pdf',
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            'text' => 'text/plain',
            'dir' => 'directory',
        ];

        return $mimeMap[$type] ?? 'application/octet-stream';
    }

    /**
     * Filter data by custom type.
     *
     * @param $files
     * @param $filter
     *
     * @return mixed
     */
    public function filterData($files, $filter)
    {
        $folders = $files->where('type', 'dir');
        $items = $files->where('type', 'file');

        $filters = config('filemanager.filters', []);
        if (count($filters) > 0) {
            $filters = array_change_key_case($filters);

            if (isset($filters[$filter])) {
                $filteredExtensions = $filters[$filter];

                $filtered = $items->filter(function ($item) use ($filteredExtensions) {
                    return in_array($item->ext, $filteredExtensions);
                });

                return $folders->merge($filtered);
            }
        }

        return $folders->merge($items);
    }

    /**
     * Order files and folders.
     *
     * @param $files
     * @param $order
     *
     * @return mixed
     */
    public function orderData($files, $order, $direction = 'asc')
    {
        $folders = $files->where('type', 'dir');
        $items = $files->where('type', 'file');

        if ($order == 'size') {
            $folders = $folders->sortByDesc($order);
            $items = $items->sortByDesc($order);
        } else {
            if ($direction == 'asc') {
                $folders = $folders->sortBy(function ($item) use ($order) {
                    return mb_strtolower($item->{$order} ?? '');
                })->values();

                $items = $items->sortBy(function ($item) use ($order) {
                    return mb_strtolower($item->{$order} ?? '');
                })->values();
            } else {
                $folders = $folders->sortByDesc(function ($item) use ($order) {
                    return mb_strtolower($item->{$order} ?? '');
                })->values();

                $items = $items->sortByDesc(function ($item) use ($order) {
                    return mb_strtolower($item->{$order} ?? '');
                })->values();
            }
        }

        return $folders->merge($items);
    }

    /**
     * Generates an id based on file.
     *
     * @param   array  $file
     *
     * @return  string
     */
    public function generateId($file)
    {
        if (isset($file['timestamp']) && $file['timestamp']) {
            return md5($this->disk.'_'.trim($file['path']).'_'.$file['timestamp']);
        }

        return md5($this->disk.'_'.trim($file['path']));
    }

    /**
     * Set Relative Path.
     *
     * @param $folder
     */
    public function setRelativePath($folder)
    {
        $defaultPath = '';

        if ($this->disk === 'public' || config("filesystems.disks.{$this->disk}.driver") === 'local') {
            try {
                $defaultPath = rtrim($this->storage->path(''), '/');
            } catch (\Exception $e) {
                $defaultPath = '';
            }
        }

        $publicPath = $defaultPath ? str_replace($defaultPath, '', $folder) : $folder;

        if ($folder !== '/' && $publicPath !== '/') {
            $this->currentPath = $this->getAppend() . '/' . ltrim($publicPath, '/');
        } else {
            $this->currentPath = $this->getAppend();
        }
    }

    /**
     * Get Append to url.
     *
     * @return mixed|string
     */
    public function getAppend()
    {
        if (in_array(config('filemanager.disk'), $this->cloudDisks)) {
            return '';
        }

        return '/storage';
    }

    /**
     * Get file type from extension ONLY (no storage API calls).
     * This is CRITICAL for performance with cloud storage.
     *
     * @param string|null $extension
     * @param string $type
     * @return string
     */
    protected function getFileTypeFromExtension($extension, $type = 'file')
    {
        if ($type === 'dir') {
            return 'dir';
        }

        if (!$extension) {
            return 'file';
        }

        $extension = strtolower($extension);

        // Image extensions
        $imageExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico',
            'tiff', 'tif', 'heic', 'heif', 'avif', 'jfif', 'pjpeg', 'pjp'
        ];
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }

        // Video extensions
        $videoExtensions = [
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v',
            'mpg', 'mpeg', '3gp', 'ogv', 'ts', 'vob'
        ];
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }

        // Audio extensions
        $audioExtensions = [
            'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma',
            'oga', 'opus', 'amr', 'aiff'
        ];
        if (in_array($extension, $audioExtensions)) {
            return 'audio';
        }

        // PDF
        if ($extension === 'pdf') {
            return 'pdf';
        }

        // Text/Document extensions
        $textExtensions = [
            'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'css', 'js',
            'json', 'xml', 'html', 'htm', 'rtf', 'md', 'markdown',
            'log', 'sql', 'php', 'py', 'java', 'cpp', 'c', 'h',
            'yaml', 'yml', 'ini', 'conf', 'config'
        ];
        if (in_array($extension, $textExtensions)) {
            return 'text';
        }

        return 'file';
    }

    /**
     * Get image dimensions for files (local only).
     *
     * @param $file
     */
    public function getImageDimesions($file)
    {
        if ($this->disk == 'public') {
            try {
                $fullPath = $this->storage->path($file['path']);
                $dimensions = @getimagesize($fullPath);
                return $dimensions ?: [false, false];
            } catch (\Exception $e) {
                return [false, false];
            }
        }

        // Skip dimensions for cloud storage (too slow)
        return [false, false];
    }

    /**
     * @param $file
     *
     * @return bool
     */
    public function accept($file)
    {
        return '.' !== substr($file['basename'], 0, 1);
    }

    /**
     * Check if file is Dot.
     *
     * @param   array   $file
     *
     * @return  bool
     */
    public function isDot($file)
    {
        return Str::startsWith($file['basename'], '.');
    }

    /**
     * @param $folder
     */
    public function generateParent($folder)
    {
        $paths = collect(explode('/', trim($folder, '/')))->filter();

        if ($paths->isEmpty()) {
            return null;
        }

        $paths->pop();

        $folderPath = $paths->isEmpty() ? '/' : '/' . $paths->implode('/');

        try {
            $asset = ($folderPath === '/') ? '' : $this->cleanSlashes($this->storage->url($folderPath));
        } catch (\Exception $e) {
            $asset = '';
        }

        return [
            'id'                => 'folder_back',
            'name'              => __('Go up'),
            'path'              => $this->cleanSlashes($folderPath),
            'type'              => 'dir',
            'mime'              => 'dir',
            'ext'               => false,
            'size'              => 0,
            'size_human'        => 0,
            'thumb'             => '',
            'asset'             => $asset,
            'can'               => true,
            'loading'           => false,
            'last_modification' => false,
            'date'              => false,
        ];
    }

    /**
     * @param $currentFolder
     */
    public function getPaths($currentFolder)
    {
        $defaultPath = '';

        if ($this->disk === 'public' || config("filesystems.disks.{$this->disk}.driver") === 'local') {
            try {
                $defaultPath = $this->cleanSlashes($this->storage->path(''));
            } catch (\Exception $e) {
                $defaultPath = '';
            }
        }

        try {
            $currentPath = $this->cleanSlashes($this->storage->path($currentFolder));
        } catch (\Exception $e) {
            $currentPath = $this->cleanSlashes($currentFolder);
        }

        $paths = $currentPath;

        if ($defaultPath && $defaultPath !== '/') {
            $paths = str_replace($defaultPath, '', $currentPath);
        }

        $paths = collect(explode('/', trim($paths, '/')))->filter();
        $goodPaths = collect([]);

        foreach ($paths as $path) {
            $goodPaths->push([
                'name' => $path,
                'path' => $this->recursivePaths($path, $paths)
            ]);
        }

        return $goodPaths->reverse();
    }

    /**
     * @param $pathCollection
     */
    public function recursivePaths($name, $pathCollection)
    {
        return Str::before($pathCollection->implode('/'), $name).$name;
    }

    /**
     * @param $timestamp
     */
    public function modificationDate($time)
    {
        try {
            return Carbon::createFromTimestamp($time)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Hide folders with .hide file.
     * Uses cached result to avoid repeated API calls.
     *
     * @param string $path
     */
    private function checkShouldHideFolder($path)
    {
        if (in_array($this->disk, $this->cloudDisks)) {
            return true;
        }

        $cacheTime = config('filemanager.cache', false);

        $check = function () use ($path) {
            try {
                $filesData = $this->storage->listContents($path, false);

                foreach ($filesData as $item) {
                    if (basename($item->path()) === '.hide') {
                        return false; // Has .hide file, should be hidden
                    }
                }

                return true; // No .hide file, should be shown
            } catch (\Exception $e) {
                return true; // On error, show the folder
            }
        };

        if ($cacheTime !== false) {
            $cacheKey = 'folder_hide_' . md5($this->disk . '_' . $path);
            return cache()->remember($cacheKey, $cacheTime, $check);
        }

        return $check();
    }

    /**
     * List directory contents and convert to array.
     * CRITICAL: Non-recursive, minimal data extraction.
     *
     * @param string $folder
     * @return array
     */
    private function listContentsAsArray($folder)
    {
        try {
            // Normalize folder path
            $folder = $folder === '/' ? '' : trim($folder, '/');

            // recursive: false - only direct children
            $listing = $this->storage->listContents($folder, false);

            $results = [];

            foreach ($listing as $item) {
                $path = $item->path();
                $basename = basename($path);

                // Skip hidden files early
                if (str_starts_with($basename, '.')) {
                    continue;
                }

                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $isDir = $item->isDir();

                $results[] = [
                    'type'      => $isDir ? 'dir' : 'file',
                    'path'      => $path,
                    'basename'  => $basename,
                    'timestamp' => (!$isDir && method_exists($item, 'lastModified')) ? $item->lastModified() : null,
                    'size'      => (!$isDir && method_exists($item, 'fileSize')) ? $item->fileSize() : 0,
                    'extension' => $extension ?: null,
                ];
            }

            return $results;

        } catch (\Exception $e) {
            \Log::error('listContentsAsArray error', [
                'folder' => $folder,
                'disk' => $this->disk,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }
}
