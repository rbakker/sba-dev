function bn274Plugin_class(name,sbaViewer) {
  sbaPlugin_class.apply(this,[name])
  this.template = 'BNA'
  this.niceName = 'Connectivity'
  this.imgWidth = 240
  this.imgHeight = 200  
  this.angle0 = 90
}
bn274Plugin_class.prototype = new sbaPlugin_class();

bn274Plugin_class.prototype.setQuery = function(mode) {
  this.mode = mode
  alert(mode)
}

bn274Plugin_class.prototype.clearCanvas = function(canvas) {
  var ctx=canvas.getContext("2d");
  var ww=canvas.width 
  var hh=canvas.height
  ctx.clearRect(0,0,ww,hh)
}

bn274Plugin_class.prototype.imgOnload = function(img,canvas,row,col) {
  var ctx=canvas.getContext("2d");
  var ww=canvas.width 
  var hh=canvas.height
  ctx.drawImage(img,this.imgWidth*col,this.imgHeight*row,this.imgWidth,this.imgHeight,0,0,ww,hh)
}

bn274Plugin_class.prototype.setAngle = function(angle,mode) {
  // anatomical connectivity
  var img = document.getElementById('bn274_den_hidden')
  if (mode & 1) {
    var angleIndex = (Math.round(angle/10)-1)
    if (angleIndex<0) angleIndex += 36
    var col = angleIndex%5
    var row = (angleIndex-col)/5
    this.imgOnload(img,document.getElementById('bn274_den'),row,col)
  }
  // functional connectivity
  var img = document.getElementById('bn274_fun_hidden')
  if (mode & 2) {    
    var angleIndex = (Math.round(angle/10))
    if (angleIndex>35) angleIndex -= 36
    var col = angleIndex%5
    var row = (angleIndex-col)/5
    this.imgOnload(img,document.getElementById('bn274_fun'),row,col)
  }
  this.angle0 = angle
}

bn274Plugin_class.prototype.activate = function(sbaViewer,divElem) {
  divElem.innerHTML = '<div id="bn274_header">Loading...</div> \
    <div style="position: relative; margin-top:2%; width:90%; padding-bottom: '+Math.round(90*this.imgHeight/this.imgWidth)+'%"> \
      <div style="position: absolute; left:0px; right:0px; top:0px; bottom:0px"><canvas id="bn274_den" style="width:100%; height:100%"/></div> \
      <div style="position: absolute; left:2%; top:2%"><img id="bn274_den_loading" src="../shared-css/ajax-loader.gif"/><span id="bn274_den_error">No data.</span></div> \
      <div style="position: absolute; left:10%; right:10%; bottom:0px"><input id="bn274_den_angle" type="range" min="0" max="360" step="10" style="width:100%"/></div> \
      <img id="bn274_den_hidden" style="display: none"/> \
    </div> \
    Anatomical connectivity (from diffusion imaging) \
    <div style="position: relative; margin-top:2%; width:90%; padding-bottom: '+Math.round(90*this.imgHeight/this.imgWidth)+'%"> \
      <div style="position: absolute; left:0px; right:0px; top:0px; bottom:0px"><canvas id="bn274_fun" style="width:100%; height:100%"/></div> \
      <div style="position: absolute; left:2%; top:2%"><img id="bn274_fun_loading" src="../shared-css/ajax-loader.gif"/><span id="bn274_fun_error">No data.</span></div> \
      <div style="position: absolute; left:10%; right:10%; bottom:0px"><input id="bn274_fun_angle" type="range" min="0" max="360" step="10" style="width:100%"/></div> \
      <img id="bn274_fun_hidden" style="display: none"/> \
    </div> \
    Functional connectivity (from resting state fMRI)'
  var me = this;
  browser.require_once('../js/minAjax.js',function() {
    me.activate(sbaViewer,divElem);
  })
  if (window.minAjax) {
    minAjax({
      url:"../templates/"+this.template+"/template/index2rgb.json",
      type:"GET",
      success: function(data){
        var index2rgb = JSON.parse(data);
        me.rgb2index = {}
        for (var k in index2rgb) { me.rgb2index[index2rgb[k]] = k }
        me.applyStateChange(sbaViewer,divElem)
      }
    });
    var den_slider = document.getElementById('bn274_den_angle')
    var fun_slider = document.getElementById('bn274_fun_angle')
    den_slider.value = fun_slider.value = this.angle0
    den_slider.oninput = fun_slider.oninput = den_slider.onchange = fun_slider.onchange = function() {
      me.setAngle(this.value,3)
      den_slider.value = fun_slider.value = this.value
    }
  }
}

bn274Plugin_class.prototype.activateBehavior = function(sbaViewer,divElem) {
  divElem.innerHTML = '<div id="bn274_header">Loading...</div>'
  var me = this;
  var ready = browser.require_once('../js/minAjax.js',function() {
    me.activate(sbaViewer,divElem);
  })
  if (ready) {
    if (!me.rgb2index) {
      ready = false
      minAjax({
        url:"../templates/"+this.template+"/template/index2rgb.json",
        type:"GET",
        success: function(data){
          var index2rgb = JSON.parse(data);
          me.rgb2index = {}
          for (var k in index2rgb) { me.rgb2index[index2rgb[k]] = k }
          me.activate(sbaViewer,divElem)
        }
      });
    }
    if (!me.BDF_FDR05) {
      ready = false
      minAjax({
        url:"../templates/"+this.template+"/behavior/BDf_FDR05.json",
        type:"GET",
        success: function(data){
          me.BDF_FDR05 = JSON.parse(data)
          me.activate(sbaViewer,divElem)
        }
      });
    }
    if (ready) {
      document.getElementById('bn274_header').innerHTML = 'Ready.'
      // CONTINUE HERE
    }
  }
}

bn274Plugin_class.prototype.applyStateChange = function(sbaViewer,divElem) {
  if (!this.rgb2index) return this.activate(sbaViewer,divElem); 
  var me = this
  var rgb = sbaViewer.acr2rgb[sbaViewer.currentAcr]
  var idx = ('000'+this.rgb2index[rgb]).slice(-3)
  var elem = document.getElementById('bn274_header');
  elem.innerHTML = 'Region '+sbaViewer.currentAcr+'; index '+idx;
  // anatomical connectivity
  var den_loading = document.getElementById('bn274_den_loading')
  var den_error = document.getElementById('bn274_den_error')
  den_loading.style.visibility = 'visible'
  den_error.style.visibility = 'hidden'
  var den = document.getElementById('bn274_den_hidden');
  den.onload = function() { 
    me.setAngle(me.angle0,1) 
    den_loading.style.visibility = 'hidden'
  }
  den.onerror = function() {
    me.clearCanvas(document.getElementById('bn274_den')) 
    den_loading.style.visibility = 'hidden'
    den_error.style.visibility = 'visible'
  }
  den.src = 'http://atlas.brainnetome.org/images/probabilities/den-'+idx+'.jpg';
  // functional connectivity
  var fun_loading = document.getElementById('bn274_fun_loading')
  var fun_error = document.getElementById('bn274_fun_error')
  fun_loading.style.visibility = 'visible'
  fun_error.style.visibility = 'hidden'
  var fun = document.getElementById('bn274_fun_hidden');
  fun.src = 'http://atlas.brainnetome.org/images/probabilities/fun-'+idx+'.jpg';
  fun.onload = function() { 
    me.setAngle(me.angle0,2) 
    fun_loading.style.visibility = 'hidden'
  }
  fun.onerror = function() {
    me.clearCanvas(document.getElementById('bn274_fun')) 
    fun_loading.style.visibility = 'hidden'
    fun_error.style.visibility = 'visible'
  }
}

