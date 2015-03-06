(function($) {
    
    $(document).ready(function() {
        $('a.get-collections').on('click', function(e) {
            e.preventDefault();
            var dspaceUrl = $('#api-url').val();
            if (dspaceUrl == '') {
                alert('Try again with the dspace url');
                return;
            }
            var url = 'dspace-connector/index/fetch';
            $.ajax({
                'url'  : url,
                'data' : {'link' : 'collections', 'dspaceUrl' : dspaceUrl },
                'type' : 'get',
                'dataType' : 'json'
            }).done(function(data) {
                data.forEach(writeCollectionLi, $('ul.collections.container'));
                //console.log(data);
            }).error(function(data) {
                alert('Something went wrong.');
            });
        });
        
        $('a.get-communities').on('click', function(e) {
            e.preventDefault();
            var dspaceUrl = $('#api-url').val();
            if (dspaceUrl == '') {
                alert('Try again with the dspace url');
                return;
            }
            var url = 'dspace-connector/index/fetch';
            $.ajax({
                'url'  : url,
                'data' : {'link' : 'communities', 'dspaceUrl' : dspaceUrl, 'expand' : 'collections' },
                'type' : 'get',
                'dataType' : 'json'
            }).done(function(data) {
                data.forEach(writeCommunityLi);
                //console.log(data);
            }).error(function(data) {
                alert('Something went wrong.');
            });
        });
        
        $('form').on('click', 'button.import-collection', function(e) {
            $('input.collection-link').prop('disabled', true);
            $(this).siblings('input.collection-link').prop('disabled', false);
        });
    });
    
    function writeCollectionLi(collectionObj) {
        // this is the container to which to append the LI
        var template = $('li.collection.template').clone();
        template.removeClass('template');
        template.find('.label').html(collectionObj.name);
        
        template.find('p.description').html(collectionObj.introductoryText);
        template.find('input').val(collectionObj.link);
        //$('ul.collections input').val(collectionObj.link);
        this.append(template);
    }
    
    function writeCommunityLi(communityObj) {
        var template = $('li.community.template').clone();
        template.removeClass('template');
        template.find('.label').html(communityObj.name);        
        template.find('p.description').html(communityObj.introductoryText);
        var container = template.find('.community-collections');
        communityObj.collections.forEach(writeCollectionLi, container);
        $('ul.communities.container').append(template);
    }
})(jQuery);

