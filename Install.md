# Installing a local copy of the Scalable Brain Atlas website and developer tools

1. To serve Scalable Brain Atlas (SBA) pages on your local machine, you need to
install a web server that supports PHP. PHP 5 and 7 should both work.
A common choice is the open source webserver Apache, with the PHP module enabled.
In the Apache setup, a folder on your computer is designated to contain web-files.
When you point the browser to *localhost*, it will display the web-content in this folder. 
In the remainder of this document, it is assumed that your web content is stored in the folder ~/websites, 
and that your put 
and that you put your copy of the Scalable Brain Atlas in /websites/scalablebrainatlas.  

2. Get the code for the Scalable Brain Atlas (development) server. This can be done in two ways:
    a. Install 'git' to be able to clone the Scalable-Brain-Atlas github repository to your computer.  
Then run the command:
> git clone https://github.com/INCF/Scalable-Brain-Atlas.git

The SBA code is using parts of two other github repositories.
You need to clone them as well:  
> git clone https://github.com/rbakker/Vectorization-of-brain-atlases.git  
> git clone https://github.com/rbakker/FancyPipe.git

The SBA code 
Copy (or link) the content of FancyPipe/fancypipe to Scalable-Brain-Atlas/fancypipe



b. Alternatively, you can also [save the repository to a zip file](https://github.com/INCF/Scalable-Brain-Atlas/archive/master.zip) and unpack it
to /websites/scalablebrainatlas.

3. The Scalable-Brain-Atlas github repository contains symbolic links to other github repositories. You only need to install these if you are going to create atlas templates.
**TODO: instructions**

4. The repository does **not** contain any atlases. These you can download from the Scalable-Brain-Atlas website. **TODO: how**

5. Point your browser to http://localhost/scalablebrainatlas/site and you should see the main page of the Scalable Brain Atlas (remember, clicking on any of the atlases will give an error unless you installed the corresponding template in the previous step).
