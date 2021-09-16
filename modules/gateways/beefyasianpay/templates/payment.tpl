<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.8/dist/clipboard.min.js"></script>
<style>
    .payment-btn-container {
        display: flex;
        justify-content: center;
    }

    #qrcode {
        display: flex;
        width: 100%;
        justify-content: center;
    }

    .address {
        width: 100%;
        border: 1px solid #eee;
        padding: 5px;
        border-radius: 4px;
    }

    .copy-botton {
        width: 100%;
    }
    .copy-botton .btn {
        width: 100%;
    }
</style>

<div style="width: 250px">
    <div id="qrcode"></div>
    <p>Valid till <span id="valid-till">{$validTill}</span></p>
    <p class="usdt-addr">
        <input id="address" class="address" value="{$address}"></span>

        <div class="copy-botton">
            <button id="clipboard-btn" class="btn btn-primary" type="button" data-clipboard-target="#address">COPY</button>
        </div>
    </p>
</div>

<script>
    const clipboard = new ClipboardJS('#clipboard-btn')
    clipboard.on('success', () => {
        $('#clipboard-btn').text('COPIED')
        setTimeout(() => {
            $('#clipboard-btn').text('COPY')
        }, 500);
    })

    new QRCode(document.querySelector('#qrcode'), {
        text: "{$address}",
        width: 200,
        height: 200,
    })

    setInterval(() => {
        fetch(window.location.href + '&act=invoice_status')
            .then(r => r.json())
            .then(r => {
                if (r.status.toLowerCase() === 'paid' || r.is_force_refresh) {
                    window.location.reload(true)
                } else {
                    document.querySelector('#valid-till').innerHTML = r.valid_till
                }
            })
    }, 15000);
</script>
