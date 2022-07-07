<?php namespace Env;

defined('_JEXEC') or die;

use Env\Registry\Registry;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;
use Joomla\Utilities\ArrayHelper;


class Env
{


	protected static $scopes = [];


	protected static $scope_current = null;


	public static function addScope($name, $path)
	{
		if (static::hasScope($name))
		{
			return true;
		}

		static::$scopes[$name] = [
			'path'  => Path::clean($path),
			'items' => []
		];

		static::findAllEnvFromFolder(
			static::$scopes[$name]['items'],
			static::$scopes[$name]['path'],
		);

		if (!is_array(static::$scopes[$name]['items']))
		{
			static::$scopes[$name]['items'] = [];
		}

		return true;
	}


	public static function setScopeConst($path)
	{
		return static::addScope('const', $path);
	}


	public static function setScope($name)
	{
		if (static::hasScope($name))
		{
			static::$scope_current = $name;

			return true;
		}

		return false;
	}


	public static function getConst($name, $default)
	{
		return static::get($name, $default, 'const');
	}


	public static function setConst($name, $value)
	{
		return static::set($name, $value, 'const', true);
	}


	public static function hasScope($name)
	{

		if (isset(static::$scopes[$name]))
		{
			return true;
		}

		return false;
	}


	public static function get($name, $default = null, $scope = null)
	{

		if ($scope === null)
		{
			$scope = static::$scope_current;
		}

		$meta = static::getMeta($name, $scope);

		if (!$meta)
		{
			return $default;
		}

		if (!isset(static::$scopes[$meta->scope]['items'][$meta->key]))
		{
			return $default;
		}

		return static::$scopes[$meta->scope]['items'][$meta->key]->get($meta->variable, $default);
	}


	public static function getFull($name, $scope = null)
	{

		if ($scope === null)
		{
			$scope = static::$scope_current;
		}

		$name .= '.plug';

		$meta = static::getMeta($name, $scope);

		if (!$meta)
		{
			return new Registry;
		}

		return static::$scopes[$meta->scope]['items'][$meta->key] ?? new Registry;
	}


	public static function set($name, $value, $scope = null, $ignore_write_check = false)
	{

		if ($scope === null)
		{
			$scope = static::$scope_current;
		}

		$meta = static::getMeta($name, $scope);

		if (!$meta)
		{
			return false;
		}

		if (!$meta->write && !$ignore_write_check)
		{
			return false;
		}

		$file       = Path::clean(static::$scopes[$meta->scope]['path'] . '/' . $meta->folder . '/' . $meta->file . '.php');
		$class_name = 'Env' . ucfirst(strtolower(str_replace('.', '', $meta->key)));

		// проверка папок
		try
		{
			if (!static::preparePath($file))
			{
				return false;
			}
		}
		catch (\Exception $e)
		{
			return false;
		}

		if (!isset(static::$scopes[$meta->scope]['items'][$meta->key]))
		{
			static::$scopes[$meta->scope]['items'][$meta->key] = new Registry();
		}

		static::$scopes[$meta->scope]['items'][$meta->key]->set($meta->variable, $value);

		$data_string = static::$scopes[$meta->scope]['items'][$meta->key]->toString('PHP', ['class' => $class_name, 'closingtag' => false]);

		if (!File::write($file, $data_string))
		{
			return false;
		}

		return true;
	}


	public static function setSome($values, $scope = null, $ignore_write_check = false)
	{
		if ($scope === null)
		{
			$scope = static::$scope_current;
		}

		if (!static::hasScope($scope))
		{
			return false;
		}

		$lists = [];

		$do = static function ($node, $key_parent = []) use (&$lists, &$do) {

			if (is_array($node))
			{
				foreach ($node as $key => $node_new)
				{
					$do($node_new, array_merge($key_parent, [$key]));
				}
			}
			else
			{
				$split     = $key_parent;
				$key_value = array_pop($split);
				$key_save  = implode('.', $split);

				if (!isset($lists[$key_save]))
				{
					$lists[$key_save] = [];
				}

				$lists[$key_save][$key_value] = $node;

			}

		};

		$do($values);

		if (!is_array($lists))
		{
			$lists = [];
		}

		foreach ($lists as $key => $values)
		{
			$split = explode('.', $key);

			if (count($split) === 0)
			{
				continue;
			}

			$file       = array_pop($split);
			$folder     = implode('/', $split);
			$key        = implode('.', array_merge($split, [$file]));
			$class_name = 'Env' . ucfirst(strtolower(str_replace('.', '', $key)));

			$path_file     = Path::clean(static::$scopes[$scope]['path'] . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $file . '.php');
			$registry_save = new Registry();
			$registry_new  = new Registry($values);

			if (isset(static::$scopes[$scope]['items'][$key]))
			{
				$registry_save = &static::$scopes[$scope]['items'][$key];
			}

			$registry_save->merge($registry_new, true);
			$data_string = $registry_save->toString('PHP', ['class' => $class_name, 'closingtag' => false]);

			if (!File::write($path_file, $data_string))
			{
				return false;
			}

		}

		return true;
	}


	/**
	 *
	 *
	 * @param   string  $name   Ключ переменной
	 * @param   string  $scope  Область окружения
	 *
	 * @return false|object
	 *
	 * @since version
	 */
	protected static function getMeta(string $name, string $scope)
	{

		if (!static::hasScope($scope))
		{
			return false;
		}

		$split = explode('.', $name);

		if (count($split) < 2)
		{
			return false;
		}


		$variable = array_pop($split);
		$file     = array_pop($split);
		$folder   = implode('/', $split);
		$key      = implode('.', array_merge($split, [$file]));

		return (object) [
			'scope'    => $scope,
			'key'      => $key,
			'folder'   => $folder,
			'file'     => $file,
			'variable' => $variable,
			'write'    => $scope !== 'const'
		];
	}


	protected static function findAllEnvFromFolder(&$output, $path, $folder_key = '')
	{

		if (!file_exists($path))
		{
			return;
		}

		$folders = Folder::folders($path);
		$files   = Folder::files($path);

		foreach ($files as $file)
		{
			$file_split = explode('.', $file);
			$ext        = strtolower(array_pop($file_split));

			if ($ext !== 'php')
			{
				continue;
			}

			$name = implode('.', $file_split);
			$key  = $folder_key . '.' . $name;

			if ($key[0] === '.')
			{
				$key = substr($key, 1);
			}

			$class_name   = 'Env' . ucfirst(mb_strtolower(str_replace('.', '', $key)));
			$output[$key] = new Registry();

			try
			{
				include_once Path::clean($path . '/' . $file);

				if (!class_exists($class_name))
				{
					continue;
				}

				$output[$key]->loadArray(ArrayHelper::fromObject(new $class_name));
			}
			catch (\Exception $e)
			{
				continue;
			}

		}

		foreach ($folders as $folder)
		{
			$path_folder    = Path::clean($path . '/' . $folder);
			$folder_key_new = $folder;

			if (!empty($folder_key))
			{
				$folder_key_new = $folder_key . '.' . $folder;
			}

			static::findAllEnvFromFolder($output, $path_folder, $folder_key_new);
		}

	}


	protected static function preparePath($path_source = null)
	{
		$paths     = explode('/', $path_source);
		$path_last = array_pop($paths);

		if (strpos($path_last, '.') === false)
		{
			$paths[] = $path_last;
		}

		$path_current = '';

		foreach ($paths as $path_new)
		{
			$path_current .= '/' . $path_new;

			if (!file_exists($path_current))
			{
				try
				{
					if (!Folder::create($path_current))
					{
						return false;
					}
				}
				catch (\Exception $e)
				{
					return false;
				}
			}

		}

		return true;
	}

}