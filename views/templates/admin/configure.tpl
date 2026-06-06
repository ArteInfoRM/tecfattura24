{*
* 2009-2026 Tecnoacquisti.com
*
* @author    Tecnoacquisti.com <helpdesk@tecnoacquisti.com>
* @copyright 2009-2026 Tecnoacquisti.com
* @license   https://opensource.org/licenses/MIT MIT License
*}

<div class="panel">
  <div class="panel-heading">
    <i class="icon-file-text"></i> {l s='Tec Fattura24 Connector' mod='tecfattura24'}
  </div>
  <p>
    {l s='This module sends orders to Fattura24 when an enabled document rule matches the reached PrestaShop order status.' mod='tecfattura24'}
  </p>
  <p>
    {l s='Invoice data is read from the invoice address. When ArteInvoice is installed, SDI and PEC are read from its address fields.' mod='tecfattura24'}
  </p>
  {if $last_test}
    <p><strong>{l s='Last API key test:' mod='tecfattura24'}</strong> {$last_test|escape:'html':'UTF-8'}</p>
  {/if}
</div>
