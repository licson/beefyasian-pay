# WHMCS USDT Payment Gateway

支持 TRC-20 USDT 转账交易，系统可完成自动化分配地址，入账等操作，配置简单，无需第三方支付平台中转，所有交易直达您的私人账户。

### Requirements

1. PHP 7.2 or greater.
2. WHMCS 8.1 or greater. (WHMCS 7 暂未测试)

### Installation

您可以使用以下命令下载最新版本的支付程序

```
git clone https://github.com/licson/beefyasian-pay
```

下载过后请按照项目目录结构将文件分别复制到 `includes/hooks` 和 `modules/gateways` 目录。

并在 WHMCS `System Setting -> Payment Gateways -> All Payment Gateways ` 启用扩展，并在 `System Setting -> Payment Gateways -> Manage Existing Gateways` 中配置相关信息。 请注意 `Addresses` 需要每行一个，为了保证支付效率，请根据自己的订单数量准备 USDT 地址。通常而言 TRC 20 交易会在半个小时内确认并完成交易。

### 运行流程

​		当用户创建并选择使用 USDT 支付时，扩展程序会从你后台填写的 USDT 地址池中随机选择一个空闲地址分配给用，有效时间默认为 30 分钟（如果更改过默认值则为您更改的时间间隔），同时前台会发起异步请求后台获取支付状态，如果地址有效期即将过期那么后台会为该地址续期，直到用户关闭页面后由 cron job 终止关联关系或支付完成。

​		系统默认会在前台页面页面每 15 秒发起一次查询并确认订单情况，如果完成支付那么会标记订单支付完成并刷新账单页面。如果用户关闭了账单页面，那么会伴随您设置的 cron 任务频率查询订单状况并标记支付情况。当订单支付完成后系统默认会释放当前地址并等待下一次交易。如果用户部分交易那么会更新账单金额，并续期当前地址，等待支付完成。

### 赞助商

感谢 [Kuaichedao](https://kuaichedao.co) 对本项目大力支持。
