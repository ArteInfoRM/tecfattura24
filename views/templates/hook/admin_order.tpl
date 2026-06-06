{*
* 2009-2026 Tecnoacquisti.com
*
* @author    Tecnoacquisti.com <helpdesk@tecnoacquisti.com>
* @copyright 2009-2026 Tecnoacquisti.com
* @license   https://opensource.org/licenses/MIT MIT License
*}

<div class="panel card">
  <div class="panel-heading card-header">
    <i class="icon-file-text"></i> {l s='Tec Fattura24' mod='tecfattura24'}
  </div>
  <div class="panel-body card-body">
    {if $tecfattura24_message}
      <div class="alert alert-info">{$tecfattura24_message|escape:'html':'UTF-8'}</div>
    {/if}

    <h4>{l s='Configured document rules' mod='tecfattura24'}</h4>
    <ul>
      {foreach from=$tecfattura24_rules item=rule}
        {if $rule.enabled}
          <li>
            {$tecfattura24_document_types[$rule.document_type]|escape:'html':'UTF-8'}
            (<code>{$rule.document_type|escape:'html':'UTF-8'}</code>)
            - {l s='trigger status ID' mod='tecfattura24'} {$rule.id_order_state|intval}
          </li>
        {/if}
      {/foreach}
    </ul>

    {if $tecfattura24_rows}
      <h4>{l s='Fattura24 document history' mod='tecfattura24'}</h4>
      {foreach from=$tecfattura24_rows item=row}
        <div class="well">
          <p>
            <strong>{l s='Document type:' mod='tecfattura24'}</strong>
            {$row.document_type|escape:'html':'UTF-8'}
          </p>
          <p>
            <strong>{l s='Status:' mod='tecfattura24'}</strong>
            {$row.status|escape:'html':'UTF-8'}
          </p>
          <p>
            <strong>{l s='Request ID:' mod='tecfattura24'}</strong>
            {$row.id_request|escape:'html':'UTF-8'}
          </p>
          {if $row.doc_id}
            <p>
              <strong>{l s='Fattura24 document ID:' mod='tecfattura24'}</strong>
              {$row.doc_id|escape:'html':'UTF-8'}
            </p>
          {/if}
          {if $row.error_message}
            <div class="alert alert-warning">
              {$row.error_message|escape:'html':'UTF-8'}
            </div>
          {/if}
          {if $row.api_response}
            <p>
              <strong>{l s='Fattura24 response:' mod='tecfattura24'}</strong>
            </p>
            <pre style="white-space: pre-wrap;">{$row.api_response|truncate:2000:'...'|escape:'html':'UTF-8'}</pre>
          {/if}
          <p>
            <strong>{l s='Attempts:' mod='tecfattura24'}</strong>
            {$row.attempts|intval}
          </p>
          <p>
            <strong>{l s='Last update:' mod='tecfattura24'}</strong>
            {$row.date_upd|escape:'html':'UTF-8'}
          </p>
        </div>
      {/foreach}
    {else}
      <p>{l s='This order has not been sent to Fattura24 yet.' mod='tecfattura24'}</p>
    {/if}

    <h4>{l s='Manual send or retry' mod='tecfattura24'}</h4>
    {foreach from=$tecfattura24_rules item=rule}
      {if $rule.enabled}
        <form method="post" action="" style="display:inline-block; margin: 0 5px 5px 0;">
          <input type="hidden" name="id_order" value="{$tecfattura24_id_order|intval}">
          <input type="hidden" name="tecfattura24_token" value="{$tecfattura24_retry_token|escape:'html':'UTF-8'}">
          <input type="hidden" name="tecfattura24_document_type" value="{$rule.document_type|escape:'html':'UTF-8'}">
          <button type="submit" name="submitTecfattura24Retry" class="btn btn-default">
            <i class="icon-refresh"></i>
            {l s='Send or retry' mod='tecfattura24'} {$rule.document_type|escape:'html':'UTF-8'}
          </button>
        </form>
      {/if}
    {/foreach}
  </div>
</div>
