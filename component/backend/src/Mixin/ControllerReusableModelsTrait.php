<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Mixin;

defined('_JEXEC') || die;

use Exception;
use Joomla\CMS\MVC\View\HtmlView;
use Joomla\CMS\MVC\View\ViewInterface;

trait ControllerReusableModelsTrait
{
	static public $_models = [];

	public function getModel($name = '', $prefix = '', $config = [])
	{
		if (empty($name))
		{
			$name = ucfirst($this->app->isClient('site') ? $this->default_view : $this->input->get('view', $this->default_view));
		}

		$prefix = ucfirst($prefix ?: $this->app->getName());

		$hash = md5(strtolower($name . $prefix));

		if (isset(self::$_models[$hash]))
		{
			return self::$_models[$hash];
		}

		self::$_models[$hash] = parent::getModel($name, $prefix, $config);

		return self::$_models[$hash];
	}

	/**
	 * @param   string  $name
	 * @param   string  $type
	 * @param   string  $prefix
	 * @param   array   $config
	 *
	 * @return  ViewInterface|HtmlView
	 * @throws  Exception
	 * @since   3.0.0
	 */
	public function getView($name = '', $type = '', $prefix = '', $config = [])
	{
		$document = $this->app->getDocument();

		if (empty($name))
		{
			$name = $this->input->get('view', $this->default_view);
		}

		if (empty($type))
		{
			$type = $document->getType();
		}

		if (empty($config))
		{
			$viewLayout = $this->input->get('layout', 'default', 'string');
			$config     = ['base_path' => $this->basePath, 'layout' => $viewLayout];
		}

		$hadView = isset(self::$views)
			&& isset(self::$views[$name])
			&& isset(self::$views[$name][$type])
			&& isset(self::$views[$name][$type][$prefix])
			&& !empty(self::$views[$name][$type][$prefix]);

		$view = parent::getView($name, $type, $prefix, $config);

		if (!$hadView)
		{
			// Get/Create the model
			$side = $this->app->isClient('site') ? 'Site' : 'Administrator';

			if ($model = $this->getModel($name, $side, ['base_path' => $this->basePath]))
			{
				// Push the model into the view (as default)
				$view->setModel($model, true);
			}

			$view->document = $document;
		}

		return $view;
	}
}