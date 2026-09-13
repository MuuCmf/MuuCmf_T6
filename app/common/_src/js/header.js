/**
 * 顶部导航菜单
 * Priority+ 模式：容器宽度不足时，将超出部分移入“更多”下拉框
 */
$(function () {
    var $menu = $('.navbar-header .index-menu');
    var $more = $menu.find('> li.nav-more');
    var $overflow = $more.find('.dropdown-menu');
    var timer;

    function adjustNav() {
        // 重新启用裁切，防止调整过程中出现换行闪烁
        $menu.addClass('nav-clipping');

        // 还原：把下拉框里的项全部移回主菜单
        $overflow.find('li').each(function () {
            $(this).insertBefore($more);
        });
        $more.addClass('hidden');

        var containerWidth = $menu.width();
        if (!containerWidth) {
            $menu.removeClass('nav-clipping');
            return;
        }

        // 测量“更多”按钮宽度（临时显示，同步执行不会触发重绘）
        $more.removeClass('hidden');
        var moreWidth = $more.outerWidth(true);
        $more.addClass('hidden');

        // 遍历菜单项，超出的移入下拉框
        var limit = containerWidth - moreWidth;
        var cumulative = 0;
        var overflowed = false;

        $menu.find('> li.nav-item').each(function () {
            var $li = $(this);
            if (overflowed) {
                $li.appendTo($overflow);
                return;
            }
            var w = $li.outerWidth(true);
            if (cumulative + w > limit) {
                overflowed = true;
                $li.appendTo($overflow);
            } else {
                cumulative += w;
            }
        });

        if (overflowed) {
            $more.removeClass('hidden');
        }
        // 调整完成，移除裁切，恢复下拉菜单展开能力
        $menu.removeClass('nav-clipping');
    }

    $(window).on('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(adjustNav, 150);
    });

    adjustNav();

    // 获取未读消息数量
    var $information = $('.information');
    var unreadUrl = $information.data('url');
    if (unreadUrl) {
        $.post(unreadUrl, {
            shopid: 0
        }, function (res) {
            if (res.code == 200) {
                var $quantity = $('.information-quantity');
                if (res.data.friendly_num == 0) {
                    $quantity.addClass('hidden');
                } else {
                    $quantity.removeClass('hidden');
                    $quantity.text(res.data.friendly_num);
                }
            }
        })
    }
});
