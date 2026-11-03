  </div><!-- /main -->
</div><!-- /layout -->
<!-- 照片查看器：点击「查看」按钮时才加载对应图片，避免列表页一次性下载全部照片 -->
<div class="photo-mask" id="photoMask" onclick="closePhoto()">
  <span class="photo-close" title="关闭">&times;</span>
  <div class="photo-title" id="photoTitle"></div>
  <img id="photoImg" src="" alt="凭证照片" onclick="event.stopPropagation()">
</div>
<script>
function viewPhoto(url, title) {
  document.getElementById('photoImg').src = url;
  document.getElementById('photoTitle').innerText = title || '照片';
  document.getElementById('photoMask').className = 'photo-mask open';
}
function closePhoto() {
  document.getElementById('photoMask').className = 'photo-mask';
  document.getElementById('photoImg').src = ''; // 关闭后释放，下次重新加载
}
// 编辑页等处的 .thumb 缩略图点击也走同一查看器
document.addEventListener('click', function (ev) {
  var t = ev.target;
  if (t.tagName === 'IMG' && t.classList.contains('thumb')) {
    viewPhoto(t.src, t.title || '照片');
  }
});
// ESC 关闭
document.addEventListener('keydown', function (ev) {
  if (ev.key === 'Escape' || ev.keyCode === 27) closePhoto();
});
</script>
</body>
</html>
