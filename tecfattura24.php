<?php
/**
 * 2009-2026 Tecnoacquisti.com
 *
 * For support feel free to contact us on our website at https://www.tecnoacquisti.com
 *
 * @author    Tecnoacquisti.com <helpdesk@tecnoacquisti.com>
 * @copyright 2009-2026 Tecnoacquisti.com
 * @license   https://opensource.org/licenses/MIT MIT License
 * @version   1.0.1
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Tecfattura24 extends Module
{
    const CFG_API_KEY = 'TECFATTURA24_API_KEY';
    const CFG_TRIGGER_STATE = 'TECFATTURA24_TRIGGER_STATE';
    const CFG_DOCUMENT_TYPE = 'TECFATTURA24_DOCUMENT_TYPE';
    const CFG_SEND_EMAIL = 'TECFATTURA24_SEND_EMAIL';
    const CFG_PAID_STATUS = 'TECFATTURA24_PAID_STATUS';
    const CFG_ALLOW_ZERO = 'TECFATTURA24_ALLOW_ZERO';
    const CFG_ID_NUMERATOR = 'TECFATTURA24_ID_NUMERATOR';
    const CFG_ID_TEMPLATE = 'TECFATTURA24_ID_TEMPLATE';
    const CFG_TIMEOUT = 'TECFATTURA24_TIMEOUT';
    const CFG_DEBUG = 'TECFATTURA24_DEBUG';
    const CFG_TEST_KEY = 'TECFATTURA24_TEST_KEY';
    const CFG_DOCUMENT_RULES = 'TECFATTURA24_DOCUMENT_RULES';

    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_ERROR = 'error';
    const DOCUMENT_NUMBER_MAX_LENGTH = 20;
    const SHOP_CODE_MAX_LENGTH = 8;
    const NUMERIC_CONFIG_MAX_LENGTH = 16;

    /**
     * Fattura24 API base URL.
     *
     * @var string
     */
    protected $baseUrl = 'https://www.app.fattura24.com/api/v0.3/';

    public function __construct()
    {
        $this->name = 'tecfattura24';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Tecnoacquisti.com';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Tec Fattura24 Connector');
        $this->description = $this->l('Send PrestaShop orders to Fattura24 when a selected order status is reached.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Tec Fattura24 Connector?');
        $this->ps_versions_compliancy = ['min' => '1.7.8.11', 'max' => _PS_VERSION_];
    }

    /**
     * Install module configuration, table and hooks.
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installConfiguration()
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayAdminOrderSideBottom');
    }

    /**
     * Uninstall module and configuration.
     *
     * @return bool
     */
    public function uninstall()
    {
        foreach ($this->getConfigurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return $this->uninstallDb() && parent::uninstall();
    }

    /**
     * Render and process the module configuration page.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitTecfattura24Module')) {
            $output .= $this->postProcess();
        }

        if (Tools::isSubmit('submitTecfattura24Rules')) {
            $output .= $this->processDocumentRules();
        }

        if (Tools::isSubmit('testTecfattura24ApiKey')) {
            $output .= $this->processApiKeyTest();
        }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'last_test' => (string) Configuration::get(self::CFG_TEST_KEY),
        ]);

        return $output
            . $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl')
            . $this->renderForm();
    }

    /**
     * Send the order to Fattura24 when one or more configured statuses are reached.
     *
     * @param array $params Hook parameters
     *
     * @return void
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (!$this->active || empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $rules = $this->getDocumentRulesForState((int) $params['newOrderStatus']->id);
        if (empty($rules)) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        foreach ($rules as $rule) {
            if ((float) $order->total_paid == 0.0 && empty($rule['allow_zero'])) {
                $this->saveError($order, $this->l('Zero-total orders are disabled in module configuration.'), $rule);
                continue;
            }

            $this->sendOrderToFattura24($order, (int) $params['newOrderStatus']->id, false, $rule);
        }
    }

    /**
     * Render status block in legacy order pages.
     *
     * @param array $params Hook parameters
     *
     * @return string
     */
    public function hookDisplayAdminOrder($params)
    {
        return $this->renderAdminOrderPanel($params);
    }

    /**
     * Render status block in modern order pages.
     *
     * @param array $params Hook parameters
     *
     * @return string
     */
    public function hookDisplayAdminOrderSideBottom($params)
    {
        return $this->renderAdminOrderPanel($params);
    }

    /**
     * Create module table.
     *
     * @return bool
     */
    protected function installDb()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tecfattura24_document` (
            `id_tecfattura24_document` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `id_order_state` INT UNSIGNED NOT NULL DEFAULT 0,
            `document_type` VARCHAR(16) NOT NULL,
            `id_request` VARCHAR(64) NOT NULL,
            `doc_id` VARCHAR(64) DEFAULT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `api_response` MEDIUMTEXT DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_tecfattura24_document`),
            UNIQUE KEY `uniq_order_document_shop` (`id_order`, `document_type`, `id_shop`),
            KEY `idx_status` (`status`),
            KEY `idx_id_request` (`id_request`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Remove module table.
     *
     * @return bool
     */
    protected function uninstallDb()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'tecfattura24_document`');
    }

    /**
     * Create default configuration values.
     *
     * @return bool
     */
    protected function installConfiguration()
    {
        $values = [
            self::CFG_API_KEY => '',
            self::CFG_TRIGGER_STATE => 0,
            self::CFG_DOCUMENT_TYPE => 'FE',
            self::CFG_SEND_EMAIL => 0,
            self::CFG_PAID_STATUS => 0,
            self::CFG_ALLOW_ZERO => 0,
            self::CFG_ID_NUMERATOR => '',
            self::CFG_ID_TEMPLATE => '',
            self::CFG_TIMEOUT => 60,
            self::CFG_DEBUG => 0,
            self::CFG_TEST_KEY => '',
            self::CFG_DOCUMENT_RULES => '',
        ];

        foreach ($values as $key => $value) {
            if (Configuration::get($key) === false) {
                if (!Configuration::updateValue($key, $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Return module configuration keys.
     *
     * @return array
     */
    protected function getConfigurationKeys()
    {
        return [
            self::CFG_API_KEY,
            self::CFG_TRIGGER_STATE,
            self::CFG_DOCUMENT_TYPE,
            self::CFG_SEND_EMAIL,
            self::CFG_PAID_STATUS,
            self::CFG_ALLOW_ZERO,
            self::CFG_ID_NUMERATOR,
            self::CFG_ID_TEMPLATE,
            self::CFG_TIMEOUT,
            self::CFG_DEBUG,
            self::CFG_TEST_KEY,
            self::CFG_DOCUMENT_RULES,
        ];
    }

    /**
     * Save configuration form values.
     *
     * @return string
     */
    protected function postProcess()
    {
        $apiKeyInput = trim((string) Tools::getValue(self::CFG_API_KEY));
        $currentApiKey = (string) Configuration::get(self::CFG_API_KEY);

        if ($apiKeyInput !== '' && !$this->isMaskedSecret($apiKeyInput)) {
            Configuration::updateValue(self::CFG_API_KEY, $apiKeyInput);
        } elseif ($apiKeyInput === '' && $currentApiKey === '') {
            Configuration::updateValue(self::CFG_API_KEY, '');
        }

        $timeout = (int) Tools::getValue(self::CFG_TIMEOUT);
        if ($timeout < 5 || $timeout > 120) {
            $timeout = 60;
        }

        Configuration::updateValue(self::CFG_TIMEOUT, $timeout);
        Configuration::updateValue(self::CFG_DEBUG, (int) Tools::getValue(self::CFG_DEBUG));

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    /**
     * Save document rule form values.
     *
     * @return string
     */
    protected function processDocumentRules()
    {
        $submittedRules = Tools::getValue('TECFATTURA24_RULES', []);
        $rules = [];
        $errors = [];

        foreach ($this->getDocumentTypes() as $documentType => $label) {
            $submittedRule = isset($submittedRules[$documentType]) && is_array($submittedRules[$documentType])
                ? $submittedRules[$documentType]
                : [];
            $enabled = !empty($submittedRule['enabled']) ? 1 : 0;
            $idOrderState = isset($submittedRule['id_order_state']) ? (int) $submittedRule['id_order_state'] : 0;

            if ($enabled && $idOrderState <= 0) {
                $errors[] = sprintf($this->l('Select a trigger status for %s.'), $label);
            }

            $numberFormat = trim(isset($submittedRule['number_format']) ? (string) $submittedRule['number_format'] : '');
            $customNumber = !empty($submittedRule['custom_number']) ? 1 : 0;
            if ($documentType === 'C') {
                $customNumber = 1;
            }
            if ($documentType === 'C' && $numberFormat === '') {
                $numberFormat = $this->getDefaultNumberFormat($documentType);
            }
            if ($customNumber && $numberFormat === '') {
                $errors[] = sprintf($this->l('Enter a number format for %s.'), $label);
            }
            if ($numberFormat !== '' && !$this->isValidNumberFormat($numberFormat)) {
                $errors[] = sprintf($this->l('Number format for %s contains unsupported characters or tokens.'), $label);
            }

            $idNumerator = trim(isset($submittedRule['id_numerator']) ? (string) $submittedRule['id_numerator'] : '');
            $idTemplate = trim(isset($submittedRule['id_template']) ? (string) $submittedRule['id_template'] : '');
            $shopCode = $this->sanitizeNumberPart(isset($submittedRule['shop_code']) ? (string) $submittedRule['shop_code'] : '');

            if ($idNumerator !== '' && !$this->isValidNumericConfigValue($idNumerator)) {
                $errors[] = sprintf($this->l('Numerator ID for %s must contain only digits.'), $label);
            }
            if ($idTemplate !== '' && !$this->isValidNumericConfigValue($idTemplate)) {
                $errors[] = sprintf($this->l('Template ID for %s must contain only digits.'), $label);
            }
            if ($shopCode !== '' && !$this->isValidShopCode($shopCode)) {
                $errors[] = sprintf($this->l('Shop code for %s must contain only letters, numbers, underscore or hyphen and must be at most %d characters.'), $label, self::SHOP_CODE_MAX_LENGTH);
            }

            $rules[$documentType] = [
                'document_type' => $documentType,
                'enabled' => $enabled,
                'id_order_state' => $idOrderState,
                'id_numerator' => $idNumerator,
                'id_template' => $idTemplate,
                'shop_code' => $shopCode,
                'custom_number' => $customNumber,
                'number_format' => $numberFormat,
                'send_email' => !empty($submittedRule['send_email']) ? 1 : 0,
                'paid_status' => !empty($submittedRule['paid_status']) ? 1 : 0,
                'allow_zero' => !empty($submittedRule['allow_zero']) ? 1 : 0,
            ];
        }

        if (!empty($errors)) {
            return $this->displayError(implode(' ', $errors));
        }

        Configuration::updateValue(self::CFG_DOCUMENT_RULES, json_encode($rules));

        return $this->displayConfirmation($this->l('Document rules updated.'));
    }

    /**
     * Test current or submitted API key.
     *
     * @return string
     */
    protected function processApiKeyTest()
    {
        $apiKeyInput = trim((string) Tools::getValue(self::CFG_API_KEY));
        $apiKey = $this->isMaskedSecret($apiKeyInput) || $apiKeyInput === ''
            ? (string) Configuration::get(self::CFG_API_KEY)
            : $apiKeyInput;

        if ($apiKey === '') {
            return $this->displayError($this->l('Enter a Fattura24 API key before running the test.'));
        }

        $response = $this->apiPost('TestKey', ['apiKey' => $apiKey]);
        $testValue = date('Y-m-d H:i:s') . ' | HTTP ' . (int) $response['http_code'];
        Configuration::updateValue(self::CFG_TEST_KEY, $testValue);

        if (!$response['success']) {
            return $this->displayError($this->l('Fattura24 API key test failed.') . ' ' . $response['error']);
        }

        $xml = @simplexml_load_string((string) $response['body']);
        if (is_object($xml) && isset($xml->returnCode) && (int) $xml->returnCode !== 1) {
            $description = isset($xml->description) ? (string) $xml->description : '';

            return $this->displayError($this->l('Fattura24 API key was rejected.') . ' ' . $description);
        }

        return $this->displayConfirmation($this->l('Fattura24 API key test completed successfully.'));
    }

    /**
     * Render module configuration form.
     *
     * @return string
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTecfattura24Module';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()])
            . $this->renderDocumentRulesForm()
            . $this->context->smarty->fetch($this->local_path . 'views/templates/admin/copyright.tpl');
    }

    /**
     * Return configuration form structure.
     *
     * @return array
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Fattura24 API settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('API key'),
                        'name' => self::CFG_API_KEY,
                        'required' => true,
                        'desc' => $this->l('Stored value is masked. Leave it unchanged to keep the current API key.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('HTTP timeout'),
                        'name' => self::CFG_TIMEOUT,
                        'suffix' => 's',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Debug log'),
                        'name' => self::CFG_DEBUG,
                        'is_bool' => true,
                        'values' => $this->getSwitchValues('debug'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Test API key'),
                        'name' => 'testTecfattura24ApiKey',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-ok',
                    ],
                ],
            ],
        ];
    }

    /**
     * Return form values.
     *
     * @return array
     */
    protected function getConfigFormValues()
    {
        return [
            self::CFG_API_KEY => $this->maskSecret((string) Configuration::get(self::CFG_API_KEY)),
            self::CFG_TIMEOUT => (int) Configuration::get(self::CFG_TIMEOUT),
            self::CFG_DEBUG => (int) Configuration::get(self::CFG_DEBUG),
        ];
    }

    /**
     * Render document rule configuration form.
     *
     * @return string
     */
    protected function renderDocumentRulesForm()
    {
        $this->context->smarty->assign([
            'tecfattura24_rules' => $this->getDocumentRules(),
            'tecfattura24_document_types' => $this->getDocumentTypes(),
            'tecfattura24_order_states' => $this->getOrderStateOptions(),
            'tecfattura24_current_index' => $this->context->link->getAdminLink('AdminModules', false)
                . '&configure=' . $this->name
                . '&tab_module=' . $this->tab
                . '&module_name=' . $this->name,
            'tecfattura24_token' => Tools::getAdminTokenLite('AdminModules'),
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/document_rules.tpl');
    }

    /**
     * Return PrestaShop order status options.
     *
     * @return array
     */
    protected function getOrderStateOptions()
    {
        $options = [
            ['id' => 0, 'name' => $this->l('Select a status')],
        ];
        $states = OrderState::getOrderStates((int) $this->context->language->id);
        foreach ($states as $state) {
            $options[] = [
                'id' => (int) $state['id_order_state'],
                'name' => '#' . (int) $state['id_order_state'] . ' - ' . (string) $state['name'],
            ];
        }

        return $options;
    }

    /**
     * Return standard switch values.
     *
     * @param string $prefix Field prefix
     *
     * @return array
     */
    protected function getSwitchValues($prefix)
    {
        return [
            [
                'id' => $prefix . '_on',
                'value' => 1,
                'label' => $this->l('Enabled'),
            ],
            [
                'id' => $prefix . '_off',
                'value' => 0,
                'label' => $this->l('Disabled'),
            ],
        ];
    }

    /**
     * Return supported Fattura24 document types.
     *
     * @return array
     */
    protected function getDocumentTypes()
    {
        return [
            'C' => $this->l('Customer order'),
            'FE' => $this->l('Electronic invoice'),
            'I' => $this->l('Invoice'),
            'I-force' => $this->l('Forced invoice'),
            'R' => $this->l('Receipt'),
        ];
    }

    /**
     * Return configured document rules.
     *
     * @return array
     */
    protected function getDocumentRules()
    {
        $rules = [];
        $storedRules = json_decode((string) Configuration::get(self::CFG_DOCUMENT_RULES), true);
        if (is_array($storedRules)) {
            $rules = $storedRules;
        }

        foreach ($this->getDocumentTypes() as $documentType => $label) {
            if (!isset($rules[$documentType]) || !is_array($rules[$documentType])) {
                $rules[$documentType] = [];
            }

            $rules[$documentType] = array_merge(
                [
                    'document_type' => $documentType,
                    'enabled' => 0,
                    'id_order_state' => 0,
                    'id_numerator' => '',
                    'id_template' => '',
                    'shop_code' => '',
                    'custom_number' => $documentType === 'C' ? 1 : 0,
                    'number_format' => $this->getDefaultNumberFormat($documentType),
                    'send_email' => 0,
                    'paid_status' => 0,
                    'allow_zero' => 0,
                ],
                $rules[$documentType]
            );
        }

        if (empty($storedRules)) {
            $legacyDocumentType = (string) Configuration::get(self::CFG_DOCUMENT_TYPE);
            if (isset($rules[$legacyDocumentType]) && (int) Configuration::get(self::CFG_TRIGGER_STATE) > 0) {
                $rules[$legacyDocumentType] = array_merge($rules[$legacyDocumentType], [
                    'enabled' => 1,
                    'id_order_state' => (int) Configuration::get(self::CFG_TRIGGER_STATE),
                    'id_numerator' => (string) Configuration::get(self::CFG_ID_NUMERATOR),
                    'id_template' => (string) Configuration::get(self::CFG_ID_TEMPLATE),
                    'custom_number' => $legacyDocumentType === 'C' ? 1 : 0,
                    'number_format' => $this->getDefaultNumberFormat($legacyDocumentType),
                    'send_email' => (int) Configuration::get(self::CFG_SEND_EMAIL),
                    'paid_status' => (int) Configuration::get(self::CFG_PAID_STATUS),
                    'allow_zero' => (int) Configuration::get(self::CFG_ALLOW_ZERO),
                ]);
            }
        }

        return $rules;
    }

    /**
     * Return enabled document rules matching an order state.
     *
     * @param int $idOrderState Order state ID
     *
     * @return array
     */
    protected function getDocumentRulesForState($idOrderState)
    {
        $matchingRules = [];
        foreach ($this->getDocumentRules() as $rule) {
            if (!empty($rule['enabled']) && (int) $rule['id_order_state'] === (int) $idOrderState) {
                $matchingRules[] = $rule;
            }
        }

        return $matchingRules;
    }

    /**
     * Return a configured document rule by type.
     *
     * @param string $documentType Document type
     *
     * @return array
     */
    protected function getDocumentRule($documentType)
    {
        $rules = $this->getDocumentRules();

        return isset($rules[$documentType]) ? $rules[$documentType] : [];
    }

    /**
     * Send an order to Fattura24.
     *
     * @param Order $order PrestaShop order
     * @param int $idOrderState Triggering state
     * @param bool $force Force retry even after an error
     * @param array|null $rule Document rule
     *
     * @return array
     */
    protected function sendOrderToFattura24(Order $order, $idOrderState, $force, array $rule = null)
    {
        if ($rule === null) {
            $rule = $this->getDocumentRule((string) Configuration::get(self::CFG_DOCUMENT_TYPE));
        }

        $documentType = !empty($rule['document_type']) ? (string) $rule['document_type'] : (string) Configuration::get(self::CFG_DOCUMENT_TYPE);
        $idRequest = $this->buildIdRequest($order, $documentType);
        $lockName = 'tecfattura24_' . (int) $order->id . '_' . (int) $order->id_shop . '_' . pSQL($documentType);
        $apiResponseBody = '';

        if (!$this->acquireLock($lockName)) {
            return ['success' => false, 'message' => $this->l('Another Fattura24 send operation is already running for this order.')];
        }

        try {
            $existing = $this->getDocumentRow((int) $order->id, (int) $order->id_shop, $documentType);
            if ($existing && $existing['status'] === self::STATUS_SENT && !$force) {
                return ['success' => true, 'message' => $this->l('Order was already sent to Fattura24.')];
            }

            if ($existing && !$force && $existing['status'] === self::STATUS_ERROR) {
                return ['success' => false, 'message' => $this->l('Previous Fattura24 send failed. Use manual retry from the order page.')];
            }

            if ($existing && $force && $existing['status'] === self::STATUS_ERROR) {
                $idRequest .= '_retry' . ((int) $existing['attempts'] + 1);
            }

            $this->upsertPendingDocument($order, $idOrderState, $documentType, $idRequest);

            $apiKey = (string) Configuration::get(self::CFG_API_KEY);
            if ($apiKey === '') {
                throw new Exception($this->l('Fattura24 API key is missing.'));
            }

            $documentNumber = $this->shouldAppendDocumentNumber($documentType, $rule)
                ? $this->buildDocumentNumber($order, $documentType, $rule)
                : '';
            if ($documentNumber !== '' && Tools::strlen($documentNumber) > self::DOCUMENT_NUMBER_MAX_LENGTH) {
                $message = sprintf(
                    $this->l('Generated Fattura24 document number exceeds %d characters: %s'),
                    self::DOCUMENT_NUMBER_MAX_LENGTH,
                    $documentNumber
                );
                throw new Exception($message);
            }

            $xml = $this->buildDocumentXml($order, $documentType, $rule);
            $payload = [
                'apiKey' => $apiKey,
                'source' => 'TecF24-Pre ' . $this->version,
                'idRequest' => $idRequest,
                'xml' => $xml,
            ];

            $this->debugLog('Sending SaveDocument for order #' . (int) $order->id . ' with idRequest ' . $idRequest);
            $response = $this->apiPost('SaveDocument', $payload);
            $apiResponseBody = (string) $response['body'];
            if (!$response['success']) {
                throw new Exception($response['error']);
            }

            $docId = $this->extractDocId($apiResponseBody);
            if ($docId === '') {
                if ($this->isAlreadyExistingDocumentResponse($apiResponseBody)) {
                    $this->markDocumentAlreadyExists($order, $documentType, $apiResponseBody);

                    return ['success' => true, 'message' => $this->l('Document already exists in Fattura24.')];
                }

                throw new Exception($this->extractApiErrorMessage($apiResponseBody));
            }

            $this->markDocumentSent($order, $documentType, $docId, $apiResponseBody);

            return ['success' => true, 'message' => $this->l('Order sent to Fattura24.')];
        } catch (Exception $e) {
            $this->markDocumentError($order, $documentType, $e->getMessage(), $apiResponseBody);

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Build Fattura24 idRequest.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Fattura24 document type
     *
     * @return string
     */
    protected function buildIdRequest(Order $order, $documentType)
    {
        return $documentType . (int) $order->id . '_' . (int) $order->id_shop;
    }

    /**
     * Build Fattura24 document XML.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Fattura24 document type
     * @param array $rule Document rule
     *
     * @return string
     */
    protected function buildDocumentXml(Order $order, $documentType, array $rule = [])
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $root = $dom->createElement('Fattura24');
        $dom->appendChild($root);
        $document = $dom->createElement('Document');
        $root->appendChild($document);

        $currency = Currency::getCurrency((int) $order->id_currency);
        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $countryIso = Country::getIsoById((int) $invoiceAddress->id_country);
        $company = trim((string) $invoiceAddress->company);
        $customerName = $company !== ''
            ? $company
            : trim((string) $invoiceAddress->firstname . ' ' . (string) $invoiceAddress->lastname);

        $this->appendText($dom, $document, 'Currency', isset($currency['iso_code']) ? $currency['iso_code'] : 'EUR');
        $this->appendCdata($dom, $document, 'FeCustomerPec', $this->getAddressProperty($invoiceAddress, 'pec'));
        $this->appendCdata($dom, $document, 'FeDestinationCode', $this->getDestinationCode($invoiceAddress, $countryIso));
        $this->appendCdata($dom, $document, 'CustomerName', $customerName);
        $this->appendCdata($dom, $document, 'CustomerAddress', trim((string) $invoiceAddress->address1 . ' ' . (string) $invoiceAddress->address2));
        $this->appendText($dom, $document, 'CustomerPostcode', (string) $invoiceAddress->postcode);
        $this->appendCdata($dom, $document, 'CustomerCity', (string) $invoiceAddress->city);
        $this->appendText($dom, $document, 'CustomerProvince', $this->getStateIso((int) $invoiceAddress->id_state));
        $this->appendText($dom, $document, 'CustomerCountry', (string) $countryIso);

        if ((string) $invoiceAddress->dni !== '') {
            $this->appendCdata($dom, $document, 'CustomerFiscalCode', Tools::strtoupper((string) $invoiceAddress->dni));
        }

        if ((string) $invoiceAddress->vat_number !== '') {
            $this->appendCdata($dom, $document, 'CustomerVatCode', $this->cleanVatNumber((string) $countryIso, (string) $invoiceAddress->vat_number));
        }

        if ((string) $customer->email !== '') {
            $this->appendCdata($dom, $document, 'CustomerEmail', (string) $customer->email);
        }

        $this->appendDeliveryData($dom, $document, $order);
        $this->appendPaymentData($dom, $document, $order, $documentType);
        $this->appendText($dom, $document, 'VatAmount', $this->formatAmount((float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl));
        $this->appendText($dom, $document, 'TotalWithoutTax', $this->formatAmount((float) $order->total_paid_tax_excl));
        $this->appendText($dom, $document, 'Total', $this->formatAmount((float) $order->total_paid));

        if ($this->shouldAppendDocumentNumber($documentType, $rule)) {
            $this->appendCdata($dom, $document, 'Number', $this->buildDocumentNumber($order, $documentType, $rule));
        }

        if ($documentType !== 'C') {
            $payments = $dom->createElement('Payments');
            $document->appendChild($payments);
            $payment = $dom->createElement('Payment');
            $payments->appendChild($payment);
            $this->appendText($dom, $payment, 'Date', date('Y-m-d'));
            $this->appendText($dom, $payment, 'Amount', $this->formatAmount((float) $order->total_paid));
            $this->appendText($dom, $payment, 'Paid', !empty($rule['paid_status']) ? 'true' : 'false');
        }

        $rows = $dom->createElement('Rows');
        $document->appendChild($rows);
        $this->appendProductRows($dom, $rows, $order);
        $this->appendDiscountRows($dom, $rows, $order);
        $this->appendShippingRow($dom, $rows, $order, $invoiceAddress);

        $this->appendCdata($dom, $document, 'FootNotes', 'PrestaShop order reference: ' . (string) $order->reference);
        $this->appendCdata($dom, $document, 'Object', 'PrestaShop order ' . (int) $order->id . ' - ' . (string) $order->reference);
        $this->appendText($dom, $document, 'DocumentType', $documentType);
        $this->appendText($dom, $document, 'SendEmail', !empty($rule['send_email']) ? 'true' : 'false');

        $idNumerator = trim(isset($rule['id_numerator']) ? (string) $rule['id_numerator'] : '');
        if ($idNumerator !== '' && $documentType !== 'C') {
            $this->appendText($dom, $document, 'IdNumerator', $idNumerator);
        }

        $idTemplate = trim(isset($rule['id_template']) ? (string) $rule['id_template'] : '');
        if ($idTemplate !== '') {
            $this->appendText($dom, $document, 'IdTemplate', $idTemplate);
        }

        return (string) $dom->saveXML();
    }

    /**
     * Return the default custom number format for a document type.
     *
     * @param string $documentType Document type
     *
     * @return string
     */
    protected function getDefaultNumberFormat($documentType)
    {
        return $documentType === 'C' ? '{order_id}-{shop_code}-{year}' : '';
    }

    /**
     * Check whether a Number node should be sent.
     *
     * @param string $documentType Document type
     * @param array $rule Document rule
     *
     * @return bool
     */
    protected function shouldAppendDocumentNumber($documentType, array $rule)
    {
        if ($documentType === 'C') {
            return true;
        }

        return !empty($rule['custom_number']);
    }

    /**
     * Build a Fattura24 document number from a rule format.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Document type
     * @param array $rule Document rule
     *
     * @return string
     */
    protected function buildDocumentNumber(Order $order, $documentType, array $rule)
    {
        $format = trim(isset($rule['number_format']) ? (string) $rule['number_format'] : '');
        if ($format === '') {
            $format = $this->getDefaultNumberFormat($documentType);
        }
        if ($format === '') {
            $format = '{order_id}';
        }

        $year = date('Y', strtotime((string) $order->date_add));
        $shopCode = $this->sanitizeNumberPart(isset($rule['shop_code']) ? (string) $rule['shop_code'] : '');
        if ($shopCode === '') {
            $shopCode = 'SHOP' . (int) $order->id_shop;
        }

        $replacements = [
            '{year}' => $year,
            '{shop_id}' => (string) (int) $order->id_shop,
            '{shop_code}' => $shopCode,
            '{order_id}' => (string) (int) $order->id,
            '{order_reference}' => $this->sanitizeNumberPart((string) $order->reference),
            '{document_type}' => $this->sanitizeNumberPart((string) $documentType),
        ];

        $number = str_replace(array_keys($replacements), array_values($replacements), $format);
        $number = $this->sanitizeDocumentNumber($number);

        return $number !== '' ? $number : (string) (int) $order->id;
    }

    /**
     * Validate a document number format.
     *
     * @param string $format Number format
     *
     * @return bool
     */
    protected function isValidNumberFormat($format)
    {
        if (!preg_match('/^[A-Za-z0-9_\-\/\.\{\}]+$/', $format)) {
            return false;
        }

        $allowedTokens = [
            '{year}',
            '{shop_id}',
            '{shop_code}',
            '{order_id}',
            '{order_reference}',
            '{document_type}',
        ];
        preg_match_all('/\{[^}]+\}/', $format, $matches);
        foreach ($matches[0] as $token) {
            if (!in_array($token, $allowedTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a Fattura24 numeric configuration ID.
     *
     * @param string $value Numeric ID
     *
     * @return bool
     */
    protected function isValidNumericConfigValue($value)
    {
        return preg_match('/^[0-9]{1,' . self::NUMERIC_CONFIG_MAX_LENGTH . '}$/', (string) $value) === 1;
    }

    /**
     * Validate a short shop code used in document numbers.
     *
     * @param string $value Shop code
     *
     * @return bool
     */
    protected function isValidShopCode($value)
    {
        return preg_match('/^[A-Z0-9_-]{1,' . self::SHOP_CODE_MAX_LENGTH . '}$/', (string) $value) === 1;
    }

    /**
     * Sanitize one document number token value.
     *
     * @param string $value Raw value
     *
     * @return string
     */
    protected function sanitizeNumberPart($value)
    {
        $value = Tools::strtoupper(trim((string) $value));
        $value = preg_replace('/[^A-Z0-9_\-\/\.]+/', '-', $value);

        return trim((string) $value, '-_/.');
    }

    /**
     * Sanitize the complete Fattura24 document number.
     *
     * @param string $number Raw number
     *
     * @return string
     */
    protected function sanitizeDocumentNumber($number)
    {
        $number = Tools::strtoupper(trim((string) $number));
        $number = preg_replace('/[^A-Z0-9_\-\/\.]+/', '-', $number);
        $number = preg_replace('/-+/', '-', (string) $number);

        return trim((string) $number, '-_/.');
    }

    /**
     * Append payment data to the XML document.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $document XML document node
     * @param Order $order PrestaShop order
     * @param string $documentType Fattura24 document type
     *
     * @return void
     */
    protected function appendPaymentData(DOMDocument $dom, DOMElement $document, Order $order, $documentType)
    {
        $payment = (string) $order->payment;
        $this->appendCdata($dom, $document, 'PaymentMethodName', $payment);
        $this->appendCdata($dom, $document, 'PaymentMethodDescription', $payment);
        if ($documentType !== 'C') {
            $this->appendText($dom, $document, 'FePaymentCode', $this->guessFePaymentCode($payment));
        }
    }

    /**
     * Append delivery data to the XML document.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $document XML document node
     * @param Order $order PrestaShop order
     *
     * @return void
     */
    protected function appendDeliveryData(DOMDocument $dom, DOMElement $document, Order $order)
    {
        if ((int) $order->id_address_delivery <= 0 || $order->isVirtual()) {
            return;
        }

        $deliveryAddress = new Address((int) $order->id_address_delivery);
        if (!Validate::isLoadedObject($deliveryAddress)) {
            return;
        }

        $deliveryName = trim((string) $deliveryAddress->company);
        if ($deliveryName === '') {
            $deliveryName = trim((string) $deliveryAddress->firstname . ' ' . (string) $deliveryAddress->lastname);
        }

        $this->appendCdata($dom, $document, 'DeliveryName', $deliveryName);
        $this->appendCdata($dom, $document, 'DeliveryAddress', trim((string) $deliveryAddress->address1 . ' ' . (string) $deliveryAddress->address2));
        $this->appendText($dom, $document, 'DeliveryPostcode', (string) $deliveryAddress->postcode);
        $this->appendCdata($dom, $document, 'DeliveryCity', (string) $deliveryAddress->city);
        $this->appendText($dom, $document, 'DeliveryProvince', $this->getStateIso((int) $deliveryAddress->id_state));
        $this->appendText($dom, $document, 'DeliveryCountry', (string) Country::getIsoById((int) $deliveryAddress->id_country));
    }

    /**
     * Guess Italian electronic invoice payment code from payment label.
     *
     * @param string $payment Payment label
     *
     * @return string
     */
    protected function guessFePaymentCode($payment)
    {
        $needle = Tools::strtolower($payment);
        if (strpos($needle, 'bank') !== false || strpos($needle, 'wire') !== false || strpos($needle, 'bonifico') !== false) {
            return 'MP05';
        }
        if (strpos($needle, 'check') !== false || strpos($needle, 'assegno') !== false) {
            return 'MP02';
        }
        if (strpos($needle, 'cash') !== false || strpos($needle, 'contrassegno') !== false) {
            return 'MP01';
        }

        return 'MP08';
    }

    /**
     * Append product rows to XML.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $rows Rows node
     * @param Order $order PrestaShop order
     *
     * @return void
     */
    protected function appendProductRows(DOMDocument $dom, DOMElement $rows, Order $order)
    {
        foreach ($order->getProducts() as $product) {
            $row = $dom->createElement('Row');
            $rows->appendChild($row);
            $description = (string) $product['product_name'];
            if (!empty($product['product_reference'])) {
                $description .= ' [' . (string) $product['product_reference'] . ']';
            }

            if (!empty($product['product_reference'])) {
                $this->appendCdata($dom, $row, 'Code', (string) $product['product_reference']);
            }
            $this->appendCdata($dom, $row, 'Description', $description);
            $this->appendText($dom, $row, 'Qty', (string) (float) $product['product_quantity']);
            $this->appendText($dom, $row, 'Price', $this->formatAmount((float) $product['unit_price_tax_excl']));
            $this->appendText($dom, $row, 'VatCode', $this->formatVatRate((float) $product['tax_rate']));
            $this->appendText($dom, $row, 'VatDescription', $this->getVatDescription((float) $product['tax_rate']));
        }
    }

    /**
     * Append discount rows to XML.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $rows Rows node
     * @param Order $order PrestaShop order
     *
     * @return void
     */
    protected function appendDiscountRows(DOMDocument $dom, DOMElement $rows, Order $order)
    {
        $discounts = $order->getCartRules();
        foreach ($discounts as $discount) {
            $amount = isset($discount['value_tax_excl']) ? (float) $discount['value_tax_excl'] : 0.0;
            if ($amount <= 0.0) {
                continue;
            }

            $row = $dom->createElement('Row');
            $rows->appendChild($row);
            $this->appendCdata($dom, $row, 'Description', $this->l('Discount') . ': ' . (string) $discount['name']);
            $this->appendText($dom, $row, 'Qty', '1');
            $this->appendText($dom, $row, 'Price', '-' . $this->formatAmount($amount));
            $this->appendText($dom, $row, 'VatCode', '0');
            $this->appendText($dom, $row, 'VatDescription', 'Discount');
        }
    }

    /**
     * Append shipping row to XML.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $rows Rows node
     * @param Order $order PrestaShop order
     * @param Address $invoiceAddress Invoice address
     *
     * @return void
     */
    protected function appendShippingRow(DOMDocument $dom, DOMElement $rows, Order $order, Address $invoiceAddress)
    {
        $shipping = (float) $order->total_shipping_tax_excl;
        if ($shipping <= 0.0) {
            return;
        }

        $carrier = new Carrier((int) $order->id_carrier);
        $taxRate = 0.0;
        if (Validate::isLoadedObject($carrier) && method_exists($carrier, 'getTaxCalculator')) {
            $calculator = $carrier->getTaxCalculator($invoiceAddress);
            if (is_object($calculator) && method_exists($calculator, 'getTotalRate')) {
                $taxRate = (float) $calculator->getTotalRate();
            }
        }

        $row = $dom->createElement('Row');
        $rows->appendChild($row);
        $this->appendCdata($dom, $row, 'Description', $this->l('Shipping'));
        $this->appendText($dom, $row, 'Qty', '1');
        $this->appendText($dom, $row, 'Price', $this->formatAmount($shipping));
        $this->appendText($dom, $row, 'VatCode', $this->formatVatRate($taxRate));
        $this->appendText($dom, $row, 'VatDescription', $this->getVatDescription($taxRate));
    }

    /**
     * Post form data to Fattura24 API.
     *
     * @param string $endpoint API endpoint
     * @param array $payload POST payload
     *
     * @return array
     */
    protected function apiPost($endpoint, array $payload)
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_code' => 0,
                'body' => '',
                'error' => $this->l('cURL extension is not available.'),
            ];
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => (int) Configuration::get(self::CFG_TIMEOUT),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TecFattura24/' . $this->version,
        ];
        curl_setopt_array($ch, $options);

        $body = (string) curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $error = (string) curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'body' => $body,
                'error' => $error,
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'body' => $body,
                'error' => $this->l('Fattura24 API returned HTTP status') . ' ' . $httpCode,
            ];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'body' => $body,
            'error' => '',
        ];
    }

    /**
     * Extract Fattura24 document ID from API response.
     *
     * @param string $body API response body
     *
     * @return string
     */
    protected function extractDocId($body)
    {
        $xml = @simplexml_load_string($body);
        if (is_object($xml) && isset($xml->docId)) {
            return trim((string) $xml->docId);
        }

        if (preg_match('/<docId>([^<]+)<\/docId>/', $body, $matches)) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    /**
     * Extract a useful Fattura24 API error message.
     *
     * @param string $body API response body
     *
     * @return string
     */
    protected function extractApiErrorMessage($body)
    {
        $fallback = $this->l('Fattura24 response does not contain a document ID.');
        $xml = @simplexml_load_string($body);
        if (!is_object($xml)) {
            return $fallback;
        }

        $parts = [];
        if (isset($xml->returnCode)) {
            $parts[] = 'returnCode: ' . (string) $xml->returnCode;
        }
        if (isset($xml->description) && trim((string) $xml->description) !== '') {
            $parts[] = trim((string) $xml->description);
        }
        if (isset($xml->error) && trim((string) $xml->error) !== '') {
            $parts[] = trim((string) $xml->error);
        }

        return empty($parts) ? $fallback : implode(' - ', $parts);
    }

    /**
     * Check if Fattura24 reports that the document already exists.
     *
     * @param string $body API response body
     *
     * @return bool
     */
    protected function isAlreadyExistingDocumentResponse($body)
    {
        return strpos(Tools::strtolower($body), 'already exists') !== false;
    }

    /**
     * Render admin order panel and handle retry action.
     *
     * @param array $params Hook parameters
     *
     * @return string
     */
    protected function renderAdminOrderPanel($params)
    {
        static $renderedOrders = [];

        $idOrder = $this->resolveHookOrderId($params);
        if ($idOrder <= 0) {
            return '';
        }

        if (isset($renderedOrders[$idOrder])) {
            return '';
        }
        $renderedOrders[$idOrder] = true;

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $message = '';
        $isRetry = Tools::isSubmit('submitTecfattura24Retry')
            && (int) Tools::getValue('id_order') === (int) $order->id
            && $this->isValidAdminToken((string) Tools::getValue('tecfattura24_token'));

        if ($isRetry) {
            $retryDocumentType = (string) Tools::getValue('tecfattura24_document_type');
            $rule = $this->getDocumentRule($retryDocumentType);
            if (empty($rule['document_type']) || empty($rule['enabled'])) {
                $message = $this->l('Invalid Fattura24 document type.');
            } elseif ((float) $order->total_paid == 0.0 && empty($rule['allow_zero'])) {
                $message = $this->l('Zero-total orders are disabled in module configuration.');
                $this->saveError($order, $message, $rule);
            } else {
                $result = $this->sendOrderToFattura24($order, (int) $order->current_state, true, $rule);
                $message = $result['message'];
            }
        }

        $rows = $this->getDocumentRows((int) $order->id, (int) $order->id_shop);
        $rules = $this->getDocumentRules();

        $this->context->smarty->assign([
            'tecfattura24_rows' => $rows,
            'tecfattura24_rules' => $rules,
            'tecfattura24_document_types' => $this->getDocumentTypes(),
            'tecfattura24_message' => $message,
            'tecfattura24_retry_token' => $this->getAdminRetryToken(),
            'tecfattura24_id_order' => (int) $order->id,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    /**
     * Resolve order ID from hook parameters.
     *
     * @param array $params Hook parameters
     *
     * @return int
     */
    protected function resolveHookOrderId($params)
    {
        if (isset($params['id_order'])) {
            return (int) $params['id_order'];
        }
        if (isset($params['order']) && Validate::isLoadedObject($params['order'])) {
            return (int) $params['order']->id;
        }
        if (isset($params['id_order_invoice'])) {
            $invoice = new OrderInvoice((int) $params['id_order_invoice']);
            if (Validate::isLoadedObject($invoice)) {
                return (int) $invoice->id_order;
            }
        }

        return (int) Tools::getValue('id_order');
    }

    /**
     * Return a document row for an order.
     *
     * @param int $idOrder Order ID
     * @param int $idShop Shop ID
     * @param string $documentType Document type
     *
     * @return array|false
     */
    protected function getDocumentRow($idOrder, $idShop, $documentType)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'tecfattura24_document`
            WHERE `id_order` = ' . (int) $idOrder . '
            AND `id_shop` = ' . (int) $idShop . '
            AND `document_type` = "' . pSQL($documentType) . '"';

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }

    /**
     * Return all document rows for an order.
     *
     * @param int $idOrder Order ID
     * @param int $idShop Shop ID
     *
     * @return array
     */
    protected function getDocumentRows($idOrder, $idShop)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'tecfattura24_document`
            WHERE `id_order` = ' . (int) $idOrder . '
            AND `id_shop` = ' . (int) $idShop . '
            ORDER BY `date_upd` DESC, `id_tecfattura24_document` DESC';
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Insert or reset a pending document row.
     *
     * @param Order $order PrestaShop order
     * @param int $idOrderState Order state
     * @param string $documentType Document type
     * @param string $idRequest Fattura24 idRequest
     *
     * @return bool
     */
    protected function upsertPendingDocument(Order $order, $idOrderState, $documentType, $idRequest)
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'tecfattura24_document`
            (`id_order`, `id_shop`, `id_order_state`, `document_type`, `id_request`, `status`, `attempts`, `date_add`, `date_upd`)
            VALUES (
                ' . (int) $order->id . ',
                ' . (int) $order->id_shop . ',
                ' . (int) $idOrderState . ',
                "' . pSQL($documentType) . '",
                "' . pSQL($idRequest) . '",
                "' . pSQL(self::STATUS_PENDING) . '",
                1,
                "' . pSQL($now) . '",
                "' . pSQL($now) . '"
            )
            ON DUPLICATE KEY UPDATE
                `id_order_state` = VALUES(`id_order_state`),
                `id_request` = VALUES(`id_request`),
                `status` = "' . pSQL(self::STATUS_PENDING) . '",
                `error_message` = NULL,
                `attempts` = `attempts` + 1,
                `date_upd` = "' . pSQL($now) . '"';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Mark document as sent.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Document type
     * @param string $docId Fattura24 document ID
     * @param string $response API response
     *
     * @return bool
     */
    protected function markDocumentSent(Order $order, $documentType, $docId, $response)
    {
        return Db::getInstance()->update(
            'tecfattura24_document',
            [
                'doc_id' => pSQL($docId),
                'status' => pSQL(self::STATUS_SENT),
                'api_response' => pSQL($response, true),
                'error_message' => null,
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ],
            '`id_order` = ' . (int) $order->id . '
                AND `id_shop` = ' . (int) $order->id_shop . '
                AND `document_type` = "' . pSQL($documentType) . '"'
        );
    }

    /**
     * Mark document as already present in Fattura24.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Document type
     * @param string $response API response
     *
     * @return bool
     */
    protected function markDocumentAlreadyExists(Order $order, $documentType, $response)
    {
        return Db::getInstance()->update(
            'tecfattura24_document',
            [
                'status' => pSQL(self::STATUS_SENT),
                'api_response' => pSQL($response, true),
                'error_message' => null,
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ],
            '`id_order` = ' . (int) $order->id . '
                AND `id_shop` = ' . (int) $order->id_shop . '
                AND `document_type` = "' . pSQL($documentType) . '"'
        );
    }

    /**
     * Mark document as failed.
     *
     * @param Order $order PrestaShop order
     * @param string $documentType Document type
     * @param string $error Error message
     *
     * @return bool
     */
    protected function markDocumentError(Order $order, $documentType, $error, $response = '')
    {
        return Db::getInstance()->update(
            'tecfattura24_document',
            [
                'status' => pSQL(self::STATUS_ERROR),
                'api_response' => pSQL($response, true),
                'error_message' => pSQL($error, true),
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ],
            '`id_order` = ' . (int) $order->id . '
                AND `id_shop` = ' . (int) $order->id_shop . '
                AND `document_type` = "' . pSQL($documentType) . '"'
        );
    }

    /**
     * Save an error row when no API call can be attempted.
     *
     * @param Order $order PrestaShop order
     * @param string $error Error message
     *
     * @return void
     */
    protected function saveError(Order $order, $error, array $rule = null)
    {
        if ($rule === null) {
            $rule = $this->getDocumentRule((string) Configuration::get(self::CFG_DOCUMENT_TYPE));
        }

        $documentType = !empty($rule['document_type']) ? (string) $rule['document_type'] : (string) Configuration::get(self::CFG_DOCUMENT_TYPE);
        $this->upsertPendingDocument($order, (int) $order->current_state, $documentType, $this->buildIdRequest($order, $documentType));
        $this->markDocumentError($order, $documentType, $error);
    }

    /**
     * Acquire a MySQL named lock.
     *
     * @param string $lockName Lock name
     *
     * @return bool
     */
    protected function acquireLock($lockName)
    {
        $sql = 'SELECT GET_LOCK("' . pSQL($lockName) . '", 0)';

        return (int) Db::getInstance()->getValue($sql) === 1;
    }

    /**
     * Release a MySQL named lock.
     *
     * @param string $lockName Lock name
     *
     * @return void
     */
    protected function releaseLock($lockName)
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK("' . pSQL($lockName) . '")');
    }

    /**
     * Append a plain text XML node.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $parent Parent node
     * @param string $name Node name
     * @param string $value Node value
     *
     * @return void
     */
    protected function appendText(DOMDocument $dom, DOMElement $parent, $name, $value)
    {
        $node = $parent->appendChild($dom->createElement($name));
        $node->appendChild($dom->createTextNode((string) $value));
    }

    /**
     * Append a CDATA XML node.
     *
     * @param DOMDocument $dom XML document
     * @param DOMElement $parent Parent node
     * @param string $name Node name
     * @param string $value Node value
     *
     * @return void
     */
    protected function appendCdata(DOMDocument $dom, DOMElement $parent, $name, $value)
    {
        $node = $parent->appendChild($dom->createElement($name));
        $node->appendChild($dom->createCDATASection((string) $value));
    }

    /**
     * Return an address dynamic property, including arteinvoice fields.
     *
     * @param Address $address Address object
     * @param string $property Property name
     *
     * @return string
     */
    protected function getAddressProperty(Address $address, $property)
    {
        if (isset($address->{$property})) {
            return (string) $address->{$property};
        }

        if (!$this->addressColumnExists($property)) {
            return '';
        }

        $value = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT `' . bqSQL($property) . '` FROM `' . _DB_PREFIX_ . 'address`
            WHERE `id_address` = ' . (int) $address->id
        );

        return $value === false ? '' : (string) $value;
    }

    /**
     * Check if an address column exists.
     *
     * @param string $property Column name
     *
     * @return bool
     */
    protected function addressColumnExists($property)
    {
        static $columns = null;

        if ($columns === null) {
            $columns = [];
            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'address`');
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (isset($row['Field'])) {
                        $columns[$row['Field']] = true;
                    }
                }
            }
        }

        return isset($columns[$property]);
    }

    /**
     * Return the Fattura24 destination code.
     *
     * @param Address $address Invoice address
     * @param string $countryIso Country ISO code
     *
     * @return string
     */
    protected function getDestinationCode(Address $address, $countryIso)
    {
        if ($countryIso !== 'IT') {
            return 'XXXXXXX';
        }

        $sdi = trim($this->getAddressProperty($address, 'sdi'));

        return $sdi === '' ? '0000000' : Tools::strtoupper($sdi);
    }

    /**
     * Return state ISO code.
     *
     * @param int $idState State ID
     *
     * @return string
     */
    protected function getStateIso($idState)
    {
        if ($idState <= 0) {
            return '';
        }

        $state = new State($idState);

        return Validate::isLoadedObject($state) ? (string) $state->iso_code : '';
    }

    /**
     * Clean VAT number for Fattura24.
     *
     * @param string $countryIso Country ISO
     * @param string $vatNumber Raw VAT number
     *
     * @return string
     */
    protected function cleanVatNumber($countryIso, $vatNumber)
    {
        $vat = preg_replace('/[^A-Za-z0-9]/', '', (string) $vatNumber);
        if (Tools::strtoupper(Tools::substr($vat, 0, 2)) === Tools::strtoupper($countryIso)) {
            return Tools::substr($vat, 2);
        }

        return $vat;
    }

    /**
     * Format amount for XML.
     *
     * @param float $amount Amount
     *
     * @return string
     */
    protected function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Format VAT rate for XML.
     *
     * @param float $rate VAT rate
     *
     * @return string
     */
    protected function formatVatRate($rate)
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }

    /**
     * Return VAT description.
     *
     * @param float $rate VAT rate
     *
     * @return string
     */
    protected function getVatDescription($rate)
    {
        return $this->formatVatRate($rate) . '%';
    }

    /**
     * Mask a secret value.
     *
     * @param string $secret Secret
     *
     * @return string
     */
    protected function maskSecret($secret)
    {
        if ($secret === '') {
            return '';
        }

        return str_repeat('*', max(8, Tools::strlen($secret) - 4)) . Tools::substr($secret, -4);
    }

    /**
     * Check if a submitted secret is masked.
     *
     * @param string $value Submitted value
     *
     * @return bool
     */
    protected function isMaskedSecret($value)
    {
        return (bool) preg_match('/^\*{4,}.{0,}$/', $value);
    }

    /**
     * Return admin retry token.
     *
     * @return string
     */
    protected function getAdminRetryToken()
    {
        return Tools::hash($this->name . '_retry_' . (int) $this->context->employee->id);
    }

    /**
     * Validate admin retry token.
     *
     * @param string $token Submitted token
     *
     * @return bool
     */
    protected function isValidAdminToken($token)
    {
        $expected = $this->getAdminRetryToken();

        return function_exists('hash_equals') ? hash_equals($expected, $token) : $expected === $token;
    }

    /**
     * Write debug message when enabled.
     *
     * @param string $message Message
     *
     * @return void
     */
    protected function debugLog($message)
    {
        if ((int) Configuration::get(self::CFG_DEBUG) !== 1) {
            return;
        }

        PrestaShopLogger::addLog('TecFattura24 - ' . $message, 1, null, 'Order', null, true);
    }
}
