{if $error}
        <div class="error">{$error}</div>
{/if}

{if $updated}
        <div class="warn">{l s="Konfigurasjon er oppdatert" mod="booking"}</div>
{/if}

{if !$fraktguide_postal_code}
	<div class="warn">{l s="Ingen produkter vil v√re tilgjengelig inntil postnummer er konfigurert" mod="fraktguide"}</div>
{/if}

<h2>{l s="Bring Fraktguide" mod="booking"}</h2>

<form method="post" action="{$smarty.server.REQUEST_URI|escape:'htmlall':'UTF-8'}">

	<fieldset style="margin-top: 15px;">
		<legend>Innstillinger</legend>
		
		<label for="fraktguide_postal_code">{l s="Fra-postnummer" mod="fraktguide"}</label>
		<div class="margin-form">
			<input type="text" id="fraktguide_postal_code" name="fraktguide_postal_code" value="{$fraktguide_postal_code}">
		</div>

		<label for="fraktguide_edi">{l s="Bruk EDI" mod="fraktguide"}</label>
		<div class="margin-form">
			<input type="checkbox" id="fraktguide_edi" name="fraktguide_edi" value="true" {if $edi}checked{/if}>
		</div>
		
		<label for="fraktguide_insurance">{l s="Bruk forsikring" mod="fraktguide"}</label>
		<div class="margin-form">
			<input type="checkbox" id="fraktguide_insurance" name="fraktguide_insurance" value="true" {if $fraktguide_insurance}checked{/if}>
		</div class="margin-form">

		<label for="fraktguide_a_post_max_price">{l s="Maks ordrepris for A-post" mod="fraktguide"}</label>
                <div class="margin-form">
                        <input type="text" id="fraktguide_a_post_max_price" name="fraktguide_a_post_max_price" value="{$fraktguide_a_post_max_price}">
                </div>

		<label for="fraktguide_debug_mode">{l s="Vis debuginfo" mod="fraktguide"}</label>
		<div class="margin-form">
			<input type="checkbox" id="fraktguide_debug_mode" name="fraktguide_debug_mode" value="true" {if $fraktguide_debug_mode}checked{/if}>
		</div>
	</fieldset>

	<fieldset style="margin-top: 15px;">
		<legend>Produkter</legend>

		{if count($fraktguide_products) == 0} 
			<div class="margin-form">
				{l s="Ingen produkter" mod="fraktguide"}
			</div>
		{/if}

		{foreach from=$fraktguide_products key=product_id item=name}
			<label for="fraktguide_product_{$product_id}">{$name}</label>

			<div class="margin-form">
				<input type="checkbox" name="fraktguide_product[]" value="{$product_id}" {if in_array($product_id, $fraktguide_selected_products)}checked{/if}>
				<input type="hidden" name="fraktguide_product_{$product_id}_name" value="{$name}">
			</div>

			<div class="margin-form">
				{$fraktguide_product_descriptions[$product_id]}
			</div>
		{/foreach}
	</fieldset>

	<fieldset style="margin-top: 15px;">
		<div class="margin-form">
			<input type="submit" class="button" id="fraktguide_submit" name="submit" value="{l s="Lagre endringer" mod="fraktguide"}">
		</div>
	</fieldset>

	{if $fraktguide_debug_mode}
	<fieldset style="margin-top: 15px;">
		<legend>{l s="Debug" mod="fraktguide"}</legend>
		{foreach from=$fraktguide_debug_info item=debug_line}
			<div>
				<pre>
					{$debug_line}
				</pre>
			</div>
		{/foreach}
	</fieldset>
	{/if}
</form>
