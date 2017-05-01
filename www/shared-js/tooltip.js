var tooltip = {
  clientXY: function(ev) {
    if (window.event) ev = window.event
    return [ev.clientX,ev.clientY]
  },
  pageXY: function(ev) {
    if (window.event) ev = window.event
    var pageX = ev.pageX
    var pageY = ev.pageY
    if (pageX === undefined) {
      pageX = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft
      pageY = e.clientY + document.body.scrollTop + document.documentElement.scrollTop
    }
    return [pageX,pageY]
  },
  show: function(ev,tip,name) {
    var id = 'TOOLTIP'+(name ? '_'+name : '');
    divElem = document.getElementById(id);
    if (divElem == undefined) {
      divElem = document.createElement("div");
      divElem.style.visibility = 'hidden';
      browser.documentBody().appendChild(divElem);
      divElem.id = id;
      divElem.style.position = 'absolute';
      divElem.style.background = '#ffd';
      divElem.style.border = '1px solid #ccc';
      divElem.style.zIndex = '20';
    }
    divElem.style.width = null;
    divElem.innerHTML = tip;
    tooltip.move(ev,name);
    divElem.style.width = ''+(divElem.offsetWidth>400 ? 400 : divElem.offsetWidth)+'px';
    divElem.style.visibility = 'visible';
  },
  move: function(ev,name) {
    var id = 'TOOLTIP'+(name ? '_'+name : '');
    divElem = document.getElementById(id);
    if (divElem != undefined) {
      var xy = tooltip.pageXY(ev);
      divElem.style.left = ''+(xy[0]+20)+'px';
      divElem.style.top = ''+xy[1]+'px';
    }
  },
  hide: function(name) {
    var id = 'TOOLTIP'+(name ? '_'+name : '');
    divElem = document.getElementById(id);
    if (divElem != undefined) {
      divElem.style.visibility = 'hidden';
//      browser.documentBody().removeChild(divElem);
    }
  },
  elemXY: function(ev,elemId) {
    var xy = tooltip.pageXY(ev);
    var elem = document.getElementById(elemId);
    var depth = 0;
    var anchorElem = browser.documentBody();
    while (elem && elem != anchorElem && depth<20) {
      xy[0] -= elem.offsetLeft-elem.scrollLeft;
      xy[1] -= elem.offsetTop-elem.scrollTop;
      elem = elem.offsetParent;      
      depth++;
    }
    return xy;
  }
}
