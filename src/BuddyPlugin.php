<?php
namespace convergine\contentbuddy;

use convergine\contentbuddy\services\ChatGPT;
use convergine\contentbuddy\services\Prompt;
use convergine\contentbuddy\variables\BuddyVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\TemplateEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use yii\base\Event;
use craft\web\UrlManager;
use craft\helpers\UrlHelper;
use craft\base\Field;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use convergine\contentbuddy\assets\BuddyAssets;
use convergine\contentbuddy\models\SettingsModel;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\i18n\PhpMessageSource;

/**
* @property Prompt $promptService;
 * @property ChatGPT $chat;
 *
 */
class BuddyPlugin extends Plugin
{
	public static $plugin;
	public ?string $name = 'Content Buddy';

	public function init() {

        /* plugin initialization */
		$this->hasCpSection = true;
		$this->hasCpSettings = true;
		parent::init();

		$this->_setComponents();
		$this->_setRoutes();
		$this->_setEvents();
	
	}

	protected function _setComponents(){
		$this->setComponents([
			'promptService' => Prompt::class,
			'chat' => ChatGPT::class,
		]);
	}

	protected function _setRoutes(){
		// Register CP routes
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			function (RegisterUrlRulesEvent $event) {


				$event->rules['convergine-contentbuddy/settings/general'] = 'convergine-contentbuddy/settings/general';
				$event->rules['convergine-contentbuddy/settings/api'] = 'convergine-contentbuddy/settings/api';
				$event->rules['convergine-contentbuddy/settings/fields'] = 'convergine-contentbuddy/settings/fields';

				$event->rules['convergine-contentbuddy/content-generator'] = 'convergine-contentbuddy/content-generator/index';

				$event->rules['convergine-contentbuddy/prompts'] = 'convergine-contentbuddy/prompts/index';
				$event->rules['convergine-contentbuddy/prompts/add'] = 'convergine-contentbuddy/prompts/create';
				$event->rules['convergine-contentbuddy/prompts/edit/<id:\d+>'] = 'convergine-contentbuddy/prompts/edit';
				$event->rules['convergine-contentbuddy/prompts/delete/<id:\d+>'] = 'convergine-contentbuddy/prompts/remove';

				$event->rules['convergine-contentbuddy/content/generate'] = 'convergine-contentbuddy/content-generator/generate';

			}
		);
	}

	protected function _setEvents(){
		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			function (Event $event) {
				$variable = $event->sender;
				$variable->set('contentbuddy', BuddyVariable::class);
			}
		);
		/**
		 * Attach button to selected fields.
		 */
		Event::on(
			Field::class,
			Field::EVENT_DEFINE_INPUT_HTML,
			static function (DefineFieldHtmlEvent $event) {
				/** @var SettingsModel $settings */
				$settings = BuddyPlugin::getInstance()->getSettings();

				if (
					array_key_exists($event->sender->id, $settings->enabledFields)
					&& $settings->enabledFields[$event->sender->id]
					&& $settings->apiToken
				){
					$event->html .= Craft::$app->view->renderTemplate('convergine-contentbuddy/_select.twig',
						[ 'event' => $event, 'hash' => StringHelper::UUID()] );
				}
			}
		);

		/**
		 * Warn user in case there are no selected fields.
		 */
		Event::on(
			BuddyPlugin::class,
			BuddyPlugin::EVENT_AFTER_SAVE_SETTINGS,
			function (Event $event) {

				/** @var SettingsModel $settings */
				$settings = BuddyPlugin::getInstance()->getSettings();

				if (!in_array(true, $settings->enabledFields, false)){
					Craft::$app->getSession()->setError(Craft::t('convergine-contentbuddy', 'Content Buddy fields are not selected in settings. Please select fields in plugin settings under \'Fields Settings\' tab.'));
				}

				if ($settings->apiToken === ''){
					Craft::$app->getSession()->setError(Craft::t('convergine-contentbuddy', 'API Access Token required.'));
				}
			}
		);

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			// Load JS before page template is rendered
			Event::on(
				View::class,
				View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
				function (TemplateEvent $event) {
					/** @var SettingsModel $settings */
					$settings = BuddyPlugin::getInstance()->getSettings();

					// Get view
					$view = Craft::$app->getView();

					// Load additional JS
					$js = Craft::$app->view->renderTemplate('convergine-contentbuddy/_scripts.twig',[
						'isNewApi'=>BuddyPlugin::getInstance()->chat->isNewApi($settings->preferredModel)
					]);
					if ($js) {
						$view->registerJs($js, View::POS_END);
					}

					if($settings->titleFieldEnabled()){
						// Load JS for title field
						$js = Craft::$app->view->renderTemplate('convergine-contentbuddy/_title_field_script.twig',[
							'hash' => StringHelper::UUID()
						]);
						if ($js) {
							$view->registerJs($js, View::POS_END);
						}
					}

				}
			);
		}

	}

	protected function createSettingsModel(): SettingsModel {
		/* plugin settings model */
		return new SettingsModel();
	}

	/**
	 * @return string|null
	 * @throws \Twig\Error\LoaderError
	 * @throws \Twig\Error\RuntimeError
	 * @throws \Twig\Error\SyntaxError
	 * @throws \yii\base\Exception
	 */
	protected function settingsHtml(): ?string
	{
		return \Craft::$app->getView()->renderTemplate(
			'convergine-contentbuddy/settings',
			[ 'settings' => $this->getSettings() ]
		);
	}

	/**
	 * @return string
	 */
	public function getPluginName(): string
	{
		return $this->name;
	}


	/**
	 * @return array|null
	 */
	public function getCpNavItem(): ?array
	{
		$nav = parent::getCpNavItem();

		$nav['label'] = \Craft::t('convergine-contentbuddy', $this->getPluginName());
		$nav['url'] = 'convergine-contentbuddy';

		if (Craft::$app->getUser()->getIsAdmin()) {
			$nav['subnav']['content-generator'] = [
				'label' => Craft::t('convergine-contentbuddy', 'Content Generator'),
				'url' => 'convergine-contentbuddy/content-generator',
			];
			$nav['subnav']['prompts'] = [
				'label' => Craft::t('convergine-contentbuddy', 'Prompts Templates'),
				'url' => 'convergine-contentbuddy/prompts',
			];
			$nav['subnav']['settings'] = [
				'label' => Craft::t('convergine-contentbuddy', 'Settings'),
				'url' => 'convergine-contentbuddy/settings/general',
			];
		}

		return $nav;
	}

	/**
	 * @return mixed
	 */
	public function getSettingsResponse(): mixed
	{
		return Craft::$app->controller->redirect(UrlHelper::cpUrl('convergine-contentbuddy/settings/general'));
	}

}