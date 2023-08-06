<div class="pay">
    <select id="chain" class="custom-select">
        {foreach from=$supportedChains key=chain item=name}
            <option value="{$chain}">{$name}</option>
        {/foreach}
    </select>
    <button id="pay" class="btn btn-primary">
        <img src="https://coin.top/production/logo/usdtlogo.png" alt="USDT" class="img-fluid">
        Pay with USDT
    </button>
</div>

<script>
    $('#pay').on('click', () => {
        const chain = $('#chain').val()
        fetch(window.location.href + '&act=create&chain=' + chain)
            .then((r) => r.json())
            .then((r) => {
                if (r.status) {
                    window.location.reload(true)
                } else {
                    alert(r.error)
                }
            })
    })
</script>

<style>
.pay {
    display: flex;
    flex-direction: column;
    width: 180px;
    margin: 0 auto;
}

.pay #chain {
    margin-bottom: 10px;
}

.pay img {
    height: 25px;
}
</style>
