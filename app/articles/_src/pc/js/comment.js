$(function(){

    var loadCommentList = function(url, data, page, callback){

        var url = url + '&page='+page;
        var loading_html = '<div class="state-box"><i class="fa fa-spinner fa-pulse"></i> 评论加载中…</div>';
        var empty_html = '<div class="state-box state-empty"><i class="fa fa-comments-o"></i><p>还没有评论，快来抢沙发吧～</p></div>';
        $('[data-role="list-section"]').html(loading_html);
        $.ajax({
            type: 'get',
            url: url,
            data: data,
            dataType: "json",
            success: function(ret){
                if (ret && ret.data.data.length > 0) {
                    var html = '';

                    html += '<div class="comment-list clearfix">';
                    var item_html = '';
                    var thisItem = ret.data;
                    for (var i = 0, len = thisItem.data.length; i < len; i++) {
                        var n = thisItem.data[i];

                        item_html += '<div class="comment-item clearfix" data-type="comment_item" data-id="'+ n.id +'">';
                            item_html += '<div class="comment-avatar">';
                                item_html += '<img src="'+ n.user_info.avatar64 +'" alt="'+ n.user_info.nickname +'">';
                            item_html += '</div>';
                            item_html += '<div class="comment-main">';
                                item_html += '<div class="comment-user">';
                                    item_html += '<span class="nickname">'+ n.user_info.nickname +'</span>';
                                    item_html += '<span class="floor">#' + (i + 1) + '</span>';
                                item_html += '</div>';
                                item_html += '<div class="comment-text">' + n.content + '</div>';
                                item_html += '<div class="comment-foot">';
                                    item_html += '<span class="comment-time"><i class="fa fa-clock-o"></i>' + n.create_time_friendly_str + '</span>';
                                    if(n.support_yesno == 1){
                                        item_html += '<span class="comment-like active" data-id="'+ n.id +'">';
                                        item_html += '<i class="fa fa-heart"></i>';
                                        item_html += '<em class="num">' + (n.support > 0 ? n.support : '赞') + '</em>';
                                        item_html += '</span>';
                                    }else{
                                        item_html += '<span class="comment-like" data-id="'+ n.id +'">';
                                        item_html += '<i class="fa fa-heart-o"></i>';
                                        item_html += '<em class="num">' + (n.support > 0 ? n.support : '赞') + '</em>';
                                        item_html += '</span>';
                                    }
                                item_html += '</div>';
                            item_html += '</div>';
                        item_html += '</div>';
                    }
                    html += item_html;
                    html += '</div>';

                    $('[data-role="list-section"]').html(html);
                }else{
                    if(page == 1){
                        //第一页无数据说明没有内容
                        $('[data-role="list-section"]').html(empty_html);
                    }
                }

                //执行回调
                callback(ret);
            },
            error: function(){
                $('[data-role="list-section"]').html('<div class="state-box state-error"><i class="fa fa-exclamation-circle"></i><p>评论加载失败，请稍后重试</p></div>');
                callback && callback();
            }
        });
    }

    window.loadCommentList = loadCommentList;

});
