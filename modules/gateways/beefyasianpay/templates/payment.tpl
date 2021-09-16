<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.8/dist/clipboard.min.js"></script>
<style>
    #qrcode {
        display: flex;
        width: 100%;
        justify-content: center;
    }

    .usdt-addr {
        font-size: 12px;
        height: 40px;
        border: 1px solid #eee;
        border-radius: 4px;
        line-height: 40px;
        text-align: left;
        padding-left: 10px;
    }

    .copy-btn {
        display: block;
        float: right;
        text-align: center;
        background: #4faf95;
        width: 55px;
        border: 1px solid #4faf95;
        height: 38px;
        line-height: 36px;
        color: #fff;
        border-radius: 0 4px 4px 0;
        cursor: pointer;
    }

    .copied {
        display: block;
        position: absolute;
        right: 0;
        background: #272727;
        height: 30px;
        width: 60px;
        color: #ffffff;
        text-align: center;
        line-height: 30px;
        border-radius: 4px;
    }
</style>

<div style="width: 350px">
    <div id="qrcode"></div>
    <p>Pay with USDT</p>
    <p>Valid till <span id="valid-till">{$validTill}</span></p>
    <p class="usdt-addr">
        <span id="address">{$address}</span>
        <button class="copy-btn" data-clipboard-target="#address">Copy</button>
        <span class="copied" style="display: none;">Copied!</span>
    </p>
</div>

<script>
    const clipboard = new ClipboardJS('.copy-btn')
    clipboard.on('success', () => {
        $('.copied').show()
        setTimeout(() => {
            $('.copied').hide()
        }, 500);
    })

    new QRCode(document.querySelector('#qrcode'), {
        text: "{$address}",
        width: 128,
        height: 128,
    })

    setInterval(() => {
        fetch(window.location.href + '&act=invoice_status')
            .then(r => r.json())
            .then(r => {
                if (r.status.toLowerCase() === 'paid') {
                    window.location.reload(true)
                } else {
                    document.querySelector('#valid-till').innerHTML = r.valid_till
                }
            })
    }, 15000);
</script>
