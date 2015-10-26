<?php

/**
 * This file is part of the NasExt extensions of Nette Framework
 *
 * Copyright (c) 2013 Dusan Hudak (http://dusan-hudak.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace NasExt\Forms\Controls;

use Nette\Application\UI\Form;
use Nette\Application\UI\ISignalReceiver;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Container;
use Nette\InvalidStateException;
use Nette\Utils\Callback;
use Nette\Utils\Html;
use Nette\Utils\Json;

/**
 * DependentSelectBox
 *
 * @author Jáchym Toušek
 * @author Dusan Hudak
 */
class DependentSelectBox extends SelectBox implements ISignalReceiver
{

	/** @var string signal name */
	const SIGNAL_NAME = 'load';

	/** @var BaseControl[] */
	private $parents;

	/** @var callable */
	private $dependentCallback;

	/** @var  bool */
	private $disabledWhenEmpty;

	/** @var  mixed */
	private $tempValue;


	/**
	 * @param string $label
	 * @param array $parents
	 * @param callable $dependentCallback
	 */
	public function __construct($label = NULL, array $parents, callable $dependentCallback)
	{
		$this->parents = (array)$parents;
		$this->dependentCallback = $dependentCallback;
		parent::__construct($label);
	}


	/**
	 * @return Html
	 * @throws InvalidStateException
	 */
	public function getControl()
	{
		$this->tryLoadItems();
		$control = parent::getControl();

		if ($this->dependentCallback !== NULL) {
			$form = $this->getForm();
			if (!$form || !$form instanceof Form) {
				throw new InvalidStateException("DependentSelectBox supports only Nette\\Application\\UI\\Form.");
			}

			$control->attrs['data-dependentselectbox'] = $form->getPresenter()->link(
				$this->lookupPath('Nette\Application\UI\Presenter') . self::NAME_SEPARATOR . self::SIGNAL_NAME . '!'
			);

			$parents = array();
			foreach ($this->parents as $parent) {
				$parents[$parent->getName()] = $parent->getHtmlId();
			}

			$control->attrs['data-dependentselectbox-parents'] = Json::encode($parents);
		}
		return $control;
	}


	/**
	 * @param bool $value
	 * @return $this
	 */
	public function setDisabledWhenEmpty($value = TRUE)
	{
		$this->disabledWhenEmpty = $value;
		return $this;
	}


	/**
	 * Returns selected key.
	 * @return scalar
	 */
	public function getValue()
	{
		$this->tryLoadItems();
		return parent::getValue();
	}


	/**
	 * Sets selected item (by key).
	 * @param  scalar
	 * @return self
	 */
	public function setValue($value)
	{
		$this->tempValue = $value;
	}


	/**
	 * Sets items from which to choose.
	 * @param  array
	 * @param  bool
	 * @return self
	 */
	public function setItems(array $items, $useKeys = TRUE)
	{
		parent::setItems($items, $useKeys);
		if ($this->tempValue !== NULL) {
			parent::setValue($this->tempValue);
		}
	}


	protected function tryLoadItems()
	{
		if ($this->shouldLoadItems()) {

			$parentsValues = array();
			foreach ($this->parents as $parent) {
				$parentsValues[$parent->getName()] = $parent->getValue();
			}

			/** @var DependentSelectBoxData $data */
			$data = Callback::invokeArgs($this->dependentCallback, array($parentsValues));
			if (!$data instanceof DependentSelectBoxData) {
				throw new \Exception('Callback for:"' . $this->getHtmlId() . '" must return DependentSelectBoxData instance!');
			}

			$items = $data->getItems();
			$this->setValue($data->getValue() !== NULL ? $data->getValue() : $this->tempValue);

			if ($items) {
				if ($this->disabledWhenEmpty == TRUE) {
					$this->setDisabled(FALSE);
					$this->setOmitted(FALSE);
				}
				$this->loadHttpData();
				$this->setItems($items);
			} else {
				if ($this->disabledWhenEmpty == TRUE) {
					$this->setDisabled();
				}
			}
		}
	}


	/**
	 * @return boolean
	 */
	protected function shouldLoadItems()
	{
		foreach ($this->parents as $parent) {
			if ($parent->hasErrors()) {
				return FALSE;
			}
		}
		return TRUE;
	}


	/**
	 * @param callable $callback
	 * @return DependentSelectBox provides fluent interface
	 */
	public function setDependentCallback($callback)
	{
		$this->dependentCallback = $callback;
		return $this;
	}


	/**
	 * @param string $signal
	 * @throws \Nette\InvalidStateException
	 */
	public function signalReceived($signal)
	{
		/** @var Presenter $presenter */
		$presenter = $this->lookup('Nette\Application\UI\Presenter');
		if ($signal === self::SIGNAL_NAME && $presenter->isAjax()) {

			if (!is_callable($this->dependentCallback)) {
				throw new InvalidStateException('Dependent callback not set.');
			}

			$parentsValues = array();
			foreach ($this->parents as $parent) {
				$parentsValues[$parent->getName()] = $presenter->getParameter($parent->getName());
			}

			/** @var DependentSelectBoxData $data */
			$data = Callback::invokeArgs($this->dependentCallback, array($parentsValues));
			if (!$data instanceof DependentSelectBoxData) {
				throw new \Exception('Callback for:"' . $this->getHtmlId() . '" must return DependentSelectBoxData instance!');
			}

			$items = $data->getItems();
			$value = $data->getValue();

			$presenter->payload->dependentselectbox = array(
				'id' => $this->getHtmlId(),
				'items' => $items,
				'value' => $value,
				'prompt' => $this->getPrompt(),
				'disabledWhenEmpty' => $this->disabledWhenEmpty,
			);

			$presenter->sendPayload();
		}
	}


	/********************* registration ****************** */

	/**
	 * Adds addDependentSelectBox() method to \Nette\Forms\Form
	 */
	public static function register()
	{
		Container::extensionMethod('addDependentSelectBox', callback(__CLASS__, 'addDependentSelectBox'));
	}


	/**
	 * @param Container $container
	 * @param string $name
	 * @param string $label
	 * @param array $parents
	 * @param callable $dependentCallback
	 * @return DependentSelectBox provides fluent interface
	 */
	public static function addDependentSelectBox(Container $container, $name, $label = NULL, array $parents, callable $dependentCallback)
	{
		$container[$name] = new self($label, $parents, $dependentCallback);
		return $container[$name];
	}
}
