/**
 * 扫码登陆
 * @type {{userInfo: null, initWechatQrcode(*=): void, callback: null, hasScan(): void}}
 */
var scan_qrcode = {
    userInfo : null,//用户信息
    callback : null,//回调
    sceneKey : null,
    apiHost  : window.location.origin,
    /**
     * @title 创建唯一ID
     */
    createSceneKey(){
        this.sceneKey =  Number(Math.random().toString().substr(2,48) + Date.now()).toString(36);
    },
    /**
     * @title 初始化二维码
     * @param elem
     */
    initWechatQrcode(elem){
        this.createSceneKey();
        let qrCodeSrc = `${this.apiHost}/api/wechat/qrcode?scene_key=${this.sceneKey}`;
        $(elem).attr('src', qrCodeSrc);
    },
    /**
     * @title 检测是否扫码
     */
    hasScan(){
        let that = this;
        $.get( `${this.apiHost}/api/wechat/has_scan`,{scene_key:this.sceneKey},function (res) {
            if (res.code == 200){
                that.userInfo = res.data;
                typeof that.callback == 'function' && that.callback();
            }else{
                setTimeout(function () {
                    that.hasScan()
                },3000)
            }
        })
    },
    /**
     * @title 登录
     */
    login(){
        let that = this;
        
        $.post( `${this.apiHost}/api/wechat/scan_login`,{scene_key:this.sceneKey},function (data) {
            if (data.code == 200) {
                toast.success(data.msg);
                if (data.data) {
                    localStorage.setItem('user_token', data.data)
                }
                if (data.url == undefined && quickLogin == "quickLogin") {
                    $('[data-role="login_info"]').append(data.msg);
                    setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                } else {
                    if (data.url == "refresh") {
                        setTimeout(function () {
                            location.href = location.href;
                        }, 1500);
                    } else {
                        setTimeout(function () {
                            location.href = data.url;
                        }, 1500);
                    }
                }
            } else {
                toast.error(data.msg);
            }
            toast.hideLoading();
        })
    }
};

if (typeof window !== 'undefined') {
    window.scan_qrcode = scan_qrcode;
}