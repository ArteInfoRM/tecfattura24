{*
* 2009-2026 Tecnoacquisti.com
*
* @author    Tecnoacquisti.com <helpdesk@tecnoacquisti.com>
* @copyright 2009-2026 Tecnoacquisti.com
* @license   https://opensource.org/licenses/MIT MIT License
*}

<form method="post" action="{$tecfattura24_current_index|escape:'html':'UTF-8'}">
  <input type="hidden" name="token" value="{$tecfattura24_token|escape:'html':'UTF-8'}">
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-random"></i> {l s='Fattura24 document rules' mod='tecfattura24'}
    </div>
    <p>
      {l s='Enable only the documents needed by this shop and assign the PrestaShop order status that triggers each Fattura24 document type.' mod='tecfattura24'}
    </p>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>{l s='Enabled' mod='tecfattura24'}</th>
            <th>{l s='Document type' mod='tecfattura24'}</th>
            <th>{l s='Trigger order status' mod='tecfattura24'}</th>
            <th>{l s='Numerator ID' mod='tecfattura24'}</th>
            <th>{l s='Template ID' mod='tecfattura24'}</th>
            <th>{l s='Shop code' mod='tecfattura24'}</th>
            <th>{l s='Custom number' mod='tecfattura24'}</th>
            <th>{l s='Number format' mod='tecfattura24'}</th>
            <th>{l s='Send email' mod='tecfattura24'}</th>
            <th>{l s='Paid' mod='tecfattura24'}</th>
            <th>{l s='Zero total' mod='tecfattura24'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$tecfattura24_document_types key=document_type item=document_label}
            {assign var=rule value=$tecfattura24_rules[$document_type]}
            <tr>
              <td>
                <input type="checkbox" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][enabled]" value="1"{if $rule.enabled} checked="checked"{/if}>
              </td>
              <td>
                <strong>{$document_label|escape:'html':'UTF-8'}</strong>
                <br>
                <code>{$document_type|escape:'html':'UTF-8'}</code>
              </td>
              <td>
                <select name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][id_order_state]" class="fixed-width-xl">
                  {foreach from=$tecfattura24_order_states item=state}
                    <option value="{$state.id|intval}"{if $rule.id_order_state == $state.id} selected="selected"{/if}>{$state.name|escape:'html':'UTF-8'}</option>
                  {/foreach}
                </select>
              </td>
              <td>
                <input type="text" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][id_numerator]" value="{$rule.id_numerator|escape:'html':'UTF-8'}" class="fixed-width-md" maxlength="16" pattern="{literal}[0-9]{1,16}{/literal}" inputmode="numeric"{if $document_type == 'C'} disabled="disabled"{/if}>
              </td>
              <td>
                <input type="text" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][id_template]" value="{$rule.id_template|escape:'html':'UTF-8'}" class="fixed-width-md" maxlength="16" pattern="{literal}[0-9]{1,16}{/literal}" inputmode="numeric">
              </td>
              <td>
                <input type="text" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][shop_code]" value="{$rule.shop_code|escape:'html':'UTF-8'}" class="fixed-width-md" maxlength="8" pattern="{literal}[A-Za-z0-9_-]{1,8}{/literal}">
              </td>
              <td>
                {if $document_type == 'C'}
                  <input type="hidden" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][custom_number]" value="1">
                {/if}
                <input type="checkbox" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][custom_number]" value="1"{if $rule.custom_number || $document_type == 'C'} checked="checked"{/if}{if $document_type == 'C'} disabled="disabled"{/if}>
              </td>
              <td>
                <input type="text" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][number_format]" value="{$rule.number_format|escape:'html':'UTF-8'}" class="fixed-width-xl" maxlength="80" pattern="{literal}[A-Za-z0-9_./{}-]+{/literal}">
              </td>
              <td>
                <input type="checkbox" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][send_email]" value="1"{if $rule.send_email} checked="checked"{/if}>
              </td>
              <td>
                <input type="checkbox" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][paid_status]" value="1"{if $rule.paid_status} checked="checked"{/if}{if $document_type == 'C'} disabled="disabled"{/if}>
              </td>
              <td>
                <input type="checkbox" name="TECFATTURA24_RULES[{$document_type|escape:'html':'UTF-8'}][allow_zero]" value="1"{if $rule.allow_zero} checked="checked"{/if}>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
    <p class="help-block">
      {l s='Customer orders use the Number field instead of Numerator ID. Default format:' mod='tecfattura24'}
      <code>{literal}{order_id}-{shop_code}-{year}{/literal}</code>.
      {l s='When Shop code is empty the module uses SHOP plus the PrestaShop shop ID.' mod='tecfattura24'}
      <br>
      {l s='Numerator ID and Template ID accept digits only. Shop code accepts letters, numbers, underscore and hyphen, up to 8 characters.' mod='tecfattura24'}
      <br>
      {l s='Available number tokens:' mod='tecfattura24'}
      <code>{literal}{year}{/literal}</code>,
      <code>{literal}{shop_id}{/literal}</code>,
      <code>{literal}{shop_code}{/literal}</code>,
      <code>{literal}{order_id}{/literal}</code>,
      <code>{literal}{order_reference}{/literal}</code>,
      <code>{literal}{document_type}{/literal}</code>.
      {l s='The generated number must not exceed 20 characters.' mod='tecfattura24'}
      {l s='Leave Template ID empty to use the Fattura24 default.' mod='tecfattura24'}
    </p>
    <div class="panel-footer">
      <button type="submit" name="submitTecfattura24Rules" class="btn btn-default pull-right">
        <i class="process-icon-save"></i> {l s='Save document rules' mod='tecfattura24'}
      </button>
    </div>
  </div>
</form>
