function d3tree_class(instanceName,treeData) {
  this.name = instanceName;
  this.root = treeData;

  this.nodeCount = 0;
}

d3tree_class.prototype.render = function(elemId) {
  var m = [20, 120, 20, 120],
    w = 1280 - m[1] - m[3],
    h = 800 - m[0] - m[2];
    
  this.root.x0 = h / 2;
  this.root.y0 = 0;

  this.tree = d3.layout.tree();
  this.tree.size([h, w]);

  this.diagonal = d3.svg.diagonal()
    .projection(function(d) { return [d.y, d.x]; });

  this.vis = d3.select("#"+elemId).append("svg:svg")
    .attr("width", w + m[1] + m[3])
    .attr("height", h + m[0] + m[2])
    .append("svg:g")
    .attr("transform", "translate(" + m[3] + "," + m[0] + ")");
   
  var me = this;
  function toggleAll(d) {
    if (d.children) {
      d.children.forEach(toggleAll);
      me.toggle(d);
    }
  }

  // Initialize the display to show a few nodes.
  this.root.children.forEach(toggleAll);

  this.update(this.root);
}

d3tree_class.prototype.update = function(source) {
  var me = this;
  var duration = d3.event && d3.event.altKey ? 5000 : 500;

  // Compute the new tree layout.
  var nodes = this.tree.nodes(this.root).reverse();

  // Normalize for fixed-depth.
  nodes.forEach(function(d) { d.y = d.depth * 180; });

  // Update the nodes…
  var node = this.vis.selectAll("g.node")
    .data(nodes, function(d) { return d.id || (d.id = ++me.nodeCount); });

  // Enter any new nodes at the parent's previous position.
  var nodeEnter = node.enter().append("svg:g")
    .attr("class", "node")
    .attr("transform", function(d) { return "translate(" + source.y0 + "," + source.x0 + ")"; })
    .on("click", function(d) { me.toggle(d); me.update(d); });

  nodeEnter.append("svg:circle")
    .attr("r", 1e-6)
    .style("fill", function(d) { return d._children ? "lightsteelblue" : "#fff"; });

  nodeEnter.append("svg:text")
    .attr("y", function(d) { return d.children || d._children ? -15 : -15; })
    .attr("dy", ".35em")
    .attr("text-anchor", function(d) { return d.children || d._children ? "middle" : "middle"; })
    .text(function(d) { return d.name; })
    .style("fill-opacity", 1e-6);

  // Transition nodes to their new position.
  var nodeUpdate = node.transition()
    .duration(duration)
    .attr("transform", function(d) { return "translate(" + d.y + "," + d.x + ")"; });

  nodeUpdate.select("circle")
    .attr("r", 4.5)
    .style("fill", function(d) { return d._children ? "lightsteelblue" : "#fff"; });

  nodeUpdate.select("text")
    .style("fill-opacity", 1);

  // Transition exiting nodes to the parent's new position.
  var nodeExit = node.exit().transition()
    .duration(duration)
    .attr("transform", function(d) { return "translate(" + source.y + "," + source.x + ")"; })
    .remove();

  nodeExit.select("circle")
    .attr("r", 1e-6);

  nodeExit.select("text")
    .style("fill-opacity", 1e-6);

  // Update the links…
  var link = this.vis.selectAll("path.link")
    .data(this.tree.links(nodes), function(d) { return d.target.id; });

  // Enter any new links at the parent's previous position.
  link.enter().insert("svg:path", "g")
    .attr("class", "link")
    .attr("d", function(d) {
      var o = {x: source.x0, y: source.y0};
      return me.diagonal({source: o, target: o});
    })
    .transition()
    .duration(duration)
    .attr("d", this.diagonal);

  // Transition links to their new position.
  link.transition()
    .duration(duration)
    .attr("d", this.diagonal);

  // Transition exiting nodes to the parent's new position.
  link.exit().transition()
    .duration(duration)
    .attr("d", function(d) {
      var o = {x: source.x, y: source.y};
      return me.diagonal({source: o, target: o});
    })
    .remove();

  // Stash the old positions for transition.
  nodes.forEach(function(d) {
    d.x0 = d.x;
    d.y0 = d.y;
  });
}

// Toggle children.
d3tree_class.prototype.toggle = function(d) {
  if (d.children) {
    d._children = d.children;
    d.children = null;
  } else {
    d.children = d._children;
    d._children = null;
  }
}